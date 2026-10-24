<?php

namespace App\Support;

/**
 * A company types its own mail server into Settings, and the server then connects to it. Without a check that is a way
 * to make PanelOS knock on its own network (a database, a metadata service, an admin port). So: only well-known mail
 * ports, and never a loopback, private, link-local or reserved address — checked as text when saving and again after
 * DNS resolution when connecting (a name that points inside is as bad as an address that does).
 */
class SmtpHostGuard
{
    public const PORTS = [25, 465, 587, 2525];

    /** @return string|null why the host is not acceptable, null when it is fine (no DNS lookup) */
    public static function problemWithHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 150) {
            return 'Enter the mail server name, for example smtppro.zoho.in.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::publicIp($host) ? null : 'That is a private or internal address, not a public mail server.';
        }
        if (!preg_match('/^(?=.{1,150}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            return 'That does not look like a mail server name. Use a name such as smtp.example.com, without http:// or a port.';
        }
        foreach (['.local', '.localhost', '.internal', '.lan', '.home', '.corp', '.intranet'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return 'That is an internal name, not a public mail server.';
            }
        }

        return null;
    }

    /** Resolves the name and refuses it if any address it points to is not public. Throws with a readable message. */
    public static function assertSafeToConnect(string $host): void
    {
        if ($problem = self::problemWithHost($host)) {
            throw new \RuntimeException($problem);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return;
        }
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips) {
            throw new \RuntimeException("Could not find a mail server called {$host}. Check the spelling.");
        }
        foreach ($ips as $ip) {
            if (!self::publicIp($ip)) {
                throw new \RuntimeException("{$host} points to an internal address, so PanelOS will not connect to it.");
            }
        }
    }

    private static function publicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
