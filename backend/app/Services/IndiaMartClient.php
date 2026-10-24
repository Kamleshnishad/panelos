<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The only code that talks to IndiaMART (LEADS_PLAN 3.1). Pull API, from IndiaMART's own help page (read 26 Oct 2026):
 *   GET https://mapi.indiamart.com/wservce/crm/crmListing/v2/?glusr_crm_key=...&start_time=...&end_time=...
 *   - times are IST, `DD-MM-YYYYHH:MM:SS` (the page's own examples also show a month name, `01-Jan-202209:00:00`);
 *   - at most 7 days between start and end; data for the last 365 days; with no times it returns the last 24 hours;
 *   - at least 5 minutes between requests (more than 5 in a minute blocks the key for 15); a key stops working after 7 days
 *     unused, and making a new key kills the old one;
 *   - answer: JSON {CODE, STATUS, MESSAGE, TOTAL_RECORDS, RESPONSE:[...]}; each row has UNIQUE_QUERY_ID, QUERY_TYPE, QUERY_TIME,
 *     SENDER_NAME, SENDER_MOBILE and/or SENDER_EMAIL, SENDER_COUNTRY_ISO, QUERY_MESSAGE (company, city, product names are not in
 *     the readable part of the page; the mapper in 3.2 accepts the likely spellings and keeps the raw row).
 * Nothing here has met a real key yet: the error wording below is matched on the words IndiaMART is known to use, and is checked
 * against a real account in 3.4. Tests run against Http::fake().
 *
 * The key travels in the query string because IndiaMART's API wants it there, so no exception text or log line from this class may be
 * shown or stored: exceptions are replaced by fixed sentences.
 */
class IndiaMartClient
{
    public const URL = 'https://mapi.indiamart.com/wservce/crm/crmListing/v2/';
    public const TIME_FORMAT = 'd-m-YH:i:s';
    public const MIN_GAP_MINUTES = 5;
    public const MAX_WINDOW_DAYS = 7;

    public static function formatTime(Carbon $t): string
    {
        return $t->copy()->setTimezone('Asia/Kolkata')->format(self::TIME_FORMAT);
    }

    /**
     * @return list<array<string,mixed>> the enquiries IndiaMART holds for that window (possibly none)
     * @throws IndiaMartException
     */
    public function fetch(string $key, Carbon $from, Carbon $to): array
    {
        if ($from->gte($to) || $from->diffInSeconds($to) > self::MAX_WINDOW_DAYS * 86400) {
            throw new IndiaMartException('That time window is not allowed (it must be forward and at most 7 days).', 'rejected');
        }

        try {
            $res = Http::timeout(20)->acceptJson()->get(self::URL, [
                'glusr_crm_key' => $key, 'start_time' => self::formatTime($from), 'end_time' => self::formatTime($to),
            ]);
        } catch (ConnectionException) {
            throw new IndiaMartException('Could not reach IndiaMART. Check again in a few minutes.', 'unreachable');
        } catch (\Throwable) {
            throw new IndiaMartException('The request to IndiaMART failed. Try again later.', 'unreachable');
        }

        $body = $res->json();
        $code = is_array($body) ? (int) ($body['CODE'] ?? 0) : 0;
        $text = is_array($body) ? trim((string) ($body['MESSAGE'] ?? '')) : '';

        // A success is a success whatever friendly words come with it; the wording checks below run only on an answer that is not one.
        if (!is_array($body) || !($res->successful() && in_array($code, [200, 204], true))) {
            if ($res->status() === 429 || $code === 429 || preg_match('/(minimum|5\s*min|too many|many request|rate|limit|blocked|wait)/i', $text)) {
                throw new IndiaMartException('IndiaMART wants at least 5 minutes between requests (and blocks a key for 15 minutes after more than 5 in a minute). Wait and try again.', 'rate_limited');
            }
            if (in_array($res->status(), [401, 403], true) || in_array($code, [401, 403], true) || preg_match('/(key|auth|expired|invalid|unauthori[sz]ed|not valid)/i', $text)) {
                throw new IndiaMartException('IndiaMART did not accept this key. A key stops working after 7 days without use, and making a new key in Lead Manager → CRM Integration cancels the old one. Make a new key and save it here.', 'bad_key');
            }
            if (!is_array($body)) {
                throw new IndiaMartException('IndiaMART sent an answer we could not read. Try again later.', 'unreadable');
            }
            throw new IndiaMartException('IndiaMART refused the request' . ($text !== '' ? ': ' . $this->clean($text, $key) : '.'), 'rejected');
        }

        $rows = $body['RESPONSE'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** IndiaMART's own words, shortened and with the key blanked out in case they ever echo it. */
    private function clean(string $text, string $key): string
    {
        return mb_substr(str_replace($key, '[key]', $text), 0, 200);
    }
}
