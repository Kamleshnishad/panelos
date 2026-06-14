<template>
  <div class="bd-wrap" v-if="batch">
    <!-- Toolbar -->
    <div class="bd-toolbar">
      <button class="btn btn-ghost" @click="$emit('back')">← Back to Batches</button>
      <div class="bd-title">
        <span class="batch-no">{{ batch.batch_no }}</span>
        <span :class="['status-badge', batch.status]">{{ statusLabel(batch.status) }}</span>
      </div>
      <div class="action-bar">
        <button v-if="batch.status === 'draft'"       class="btn btn-start"    :disabled="actionLoading" @click="doStart">Start Production</button>
        <button v-if="batch.status === 'in_progress'" class="btn btn-complete" :disabled="actionLoading" @click="showCompleteModal = true">Complete Batch</button>
        <button v-if="batch.status === 'draft'"       class="btn btn-danger"   :disabled="actionLoading" @click="showDeleteModal = true">Delete</button>
        <button v-if="batch.order" class="btn btn-ghost" @click="$emit('view-order', batch.order_id)">View Order</button>
      </div>
    </div>

    <div v-if="actionError"   class="error-banner">{{ actionError }}</div>
    <div v-if="actionSuccess" class="success-banner">{{ actionSuccess }}</div>

    <!-- Info grid -->
    <div class="info-grid card">
      <div class="info-cell">
        <div class="info-label">Order</div>
        <div class="info-value mono bold">{{ batch.order?.order_no ?? '—' }}</div>
        <div class="info-sub">{{ batch.order?.customer?.name }}</div>
      </div>
      <div class="info-cell" v-if="batch.order?.project_name">
        <div class="info-label">Project</div>
        <div class="info-value bold">{{ batch.order.project_name }}</div>
        <div class="info-sub">{{ batch.order.project_location }}</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Quantity</div>
        <div class="info-value bold">{{ fmtQty(batch.planned_quantity) }} SQM planned</div>
        <div class="info-sub" v-if="batch.completed_quantity">{{ fmtQty(batch.completed_quantity) }} SQM completed</div>
      </div>
      <div class="info-cell">
        <div class="info-label">Timeline</div>
        <div class="info-value">Started: {{ batch.started_at ? fmtDate(batch.started_at) : '—' }}</div>
        <div class="info-sub">Completed: {{ batch.completed_at ? fmtDate(batch.completed_at) : '—' }}</div>
        <div class="info-sub" v-if="batchElapsed">Total elapsed: <strong>{{ batchElapsed }}</strong></div>
      </div>
    </div>

    <!-- Notes (editable in draft) -->
    <div class="card edit-card" v-if="batch.status === 'draft'">
      <div class="edit-row">
        <div class="form-group flex-1">
          <label>Batch Notes</label>
          <input v-model="editNotes" placeholder="Any special instructions for this batch…" />
        </div>
        <button class="btn btn-save" :disabled="saving" @click="saveNotes">{{ saving ? 'Saving…' : 'Save' }}</button>
      </div>
    </div>
    <div class="card notes-display" v-else-if="batch.notes">
      <span class="notes-label">Notes:</span> {{ batch.notes }}
    </div>

    <!-- ── Stage Progress Timeline ───────────────────────── -->
    <div class="card">
      <div class="timeline-card-header">
        <h3>Production Stage Timeline</h3>
        <button class="btn-refresh" :disabled="loadingProgress" @click="loadProgress" title="Refresh">↻ Refresh</button>
      </div>

      <!-- Overall progress bar -->
      <div class="progress-bar-wrap" v-if="stageProgress.length > 0">
        <div class="progress-track">
          <div class="progress-fill" :style="{ width: overallPct + '%' }"></div>
        </div>
        <div class="progress-label">
          <span>{{ completedStages }} / {{ stageProgress.length }} stages complete</span>
          <span class="progress-pct">{{ overallPct }}%</span>
        </div>
      </div>

      <div v-if="loadingProgress" class="loading-hint">Loading stages…</div>
      <div v-else class="stage-timeline">
        <div
          v-for="(stage, si) in stageProgress"
          :key="stage.stage_id"
          class="stage-row"
          :class="stage.status"
        >
          <!-- Connector -->
          <div class="stage-connector">
            <div class="stage-dot" :class="stage.status">
              <span v-if="stage.status === 'completed'" class="dot-check">✓</span>
              <span v-else-if="stage.status === 'in_progress'" class="dot-spin">●</span>
            </div>
            <div class="stage-line" v-if="si < stageProgress.length - 1" :class="{ 'line-done': stage.status === 'completed' }"></div>
          </div>

          <!-- Content -->
          <div class="stage-content">
            <div class="stage-header">
              <span class="stage-name">{{ stage.stage_name }}</span>
              <span :class="['stage-status-badge', stage.status]">{{ stageStatusLabel(stage.status) }}</span>
              <!-- Duration for completed stages -->
              <span class="stage-duration" v-if="stage.status === 'completed' && stage.duration_minutes != null">
                {{ formatDuration(stage.duration_minutes) }}
              </span>
              <!-- Live elapsed timer for active stage -->
              <span class="stage-elapsed" v-if="stage.status === 'in_progress' && activeStageElapsed">
                {{ activeStageElapsed }} elapsed
              </span>
            </div>

            <!-- Timestamps -->
            <div class="stage-meta" v-if="stage.started_at">
              Started: {{ fmtDateTime(stage.started_at) }}
              <span v-if="stage.completed_at"> &nbsp;→&nbsp; Done: {{ fmtDateTime(stage.completed_at) }}</span>
            </div>

            <!-- Saved notes -->
            <div class="stage-notes-display" v-if="stage.notes">
              <span class="notes-icon">📝</span> {{ stage.notes }}
            </div>

            <!-- Action area (only while batch is in_progress) -->
            <div class="stage-actions" v-if="batch.status === 'in_progress'">

              <!-- Start button + optional notes -->
              <template v-if="stage.status === 'pending'">
                <div class="stage-action-row">
                  <input
                    v-model="stageNotes[stage.stage_id]"
                    class="stage-note-input"
                    placeholder="Optional start note…"
                    :disabled="!canStartStage(si)"
                  />
                  <button
                    class="btn-stage btn-stage-start"
                    :disabled="actionLoading || !canStartStage(si)"
                    :title="!canStartStage(si) ? 'Complete previous stage first' : 'Start ' + stage.stage_name"
                    @click="startStage(stage)"
                  >▶ Start</button>
                </div>
                <div class="locked-hint" v-if="!canStartStage(si)">
                  Complete <em>{{ stageProgress[si - 1]?.stage_name }}</em> first
                </div>
              </template>

              <!-- Complete button + optional notes -->
              <template v-if="stage.status === 'in_progress'">
                <div class="stage-action-row">
                  <input
                    v-model="stageNotes[stage.stage_id]"
                    class="stage-note-input"
                    placeholder="Optional completion note…"
                  />
                  <button
                    class="btn-stage btn-stage-done"
                    :disabled="actionLoading"
                    @click="completeStage(stage)"
                  >✓ Mark Done</button>
                </div>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- All-stages-done prompt -->
      <div class="all-done-hint" v-if="allStagesDone && batch.status === 'in_progress'">
        ✅ All stages complete — click <strong>Complete Batch</strong> above to move to QC.
      </div>
    </div>

    <!-- Panel BOQ (from linked order) -->
    <div class="card" v-if="batch.order?.items?.length">
      <h3>Panel Specification</h3>
      <div v-for="(item, ii) in batch.order.items" :key="item.id" class="item-block">
        <div class="item-header">
          <span class="item-num">{{ ii + 1 }}</span>
          <div class="item-spec">
            <div class="spec-title">{{ item.panel_type?.name }}</div>
            <div class="spec-detail">
              {{ item.thickness }}mm | {{ item.density_type }} {{ item.density_kgm3 }} kg/m³ |
              TOP: {{ item.top_skin_thickness }}mm {{ item.top_skin_material }} {{ item.top_color }} ({{ item.top_surface }}) |
              BTM: {{ item.bottom_skin_thickness }}mm {{ item.bottom_skin_material }}
              <span v-if="item.guard_film"> | Guard Film</span>
              <span v-if="item.cello_tap"> | Cello Tap</span>
            </div>
          </div>
          <div class="item-total">
            <div class="item-sqm">{{ fmtQty(item.total_sqm) }} SQM</div>
          </div>
        </div>
        <table class="size-table" v-if="item.sizes?.length">
          <thead>
            <tr>
              <th>Length (mm)</th>
              <th class="text-center">Width</th>
              <th class="text-center">NOS</th>
              <th class="text-center">SQM</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sz in item.sizes" :key="sz.id">
              <td class="bold">
                {{ sz.length_mm }}
                <span v-if="sz.length_mm < 2000" class="warn-tag">⚠ DL</span>
              </td>
              <td class="text-center">{{ sz.width_mm }}</td>
              <td class="text-center bold">{{ sz.nos }}</td>
              <td class="text-center">{{ Number(sz.sqm).toFixed(3) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- QC section -->
    <div class="card qc-card" v-if="['qc_pending','qc_passed','qc_failed','completed'].includes(batch.status)">
      <h3>Quality Control Inspection</h3>

      <!-- ── Already inspected: show result ── -->
      <template v-if="qc">
        <div class="qc-result-bar" :class="qc.status">
          <span class="qc-result-icon">{{ qc.status === 'pass' ? '✅' : '❌' }}</span>
          <span class="qc-result-label">{{ qc.status === 'pass' ? 'PASSED' : 'FAILED' }}</span>
        </div>

        <div class="qc-detail-grid">
          <div class="qc-detail-cell">
            <div class="qc-detail-label">Inspected By</div>
            <div class="qc-detail-value">{{ qc.inspected_by_user?.name ?? 'Unknown' }}</div>
          </div>
          <div class="qc-detail-cell">
            <div class="qc-detail-label">Inspected At</div>
            <div class="qc-detail-value">{{ qc.inspected_at ? fmtDateTime(qc.inspected_at) : '—' }}</div>
          </div>
          <div class="qc-detail-cell" v-if="qc.approved_by_user">
            <div class="qc-detail-label">Approved By</div>
            <div class="qc-detail-value">{{ qc.approved_by_user.name }}</div>
          </div>
          <div class="qc-detail-cell" v-if="qc.approved_at">
            <div class="qc-detail-label">Approved At</div>
            <div class="qc-detail-value">{{ fmtDateTime(qc.approved_at) }}</div>
          </div>
        </div>

        <!-- Checklist results -->
        <div class="qc-checklist-results" v-if="qcChecklist.length">
          <div class="qc-checklist-label">Inspection Checklist</div>
          <div class="qc-check-row" v-for="item in qcChecklist" :key="item.key">
            <span class="check-icon" :class="item.passed ? 'pass' : 'fail'">{{ item.passed ? '✓' : '✗' }}</span>
            <span class="check-label">{{ item.label }}</span>
          </div>
        </div>

        <div class="qc-notes-block" v-if="qc.notes">
          <div class="qc-detail-label">Inspector Notes</div>
          <div class="qc-notes-text">{{ qc.notes }}</div>
        </div>

        <!-- Approve button — only if not yet approved -->
        <div class="qc-approve-row" v-if="!qc.approved_at && ['pass','fail'].includes(qc.status)">
          <input v-model="approveNote" class="qc-note-input" placeholder="Approval note (optional)…" />
          <button class="btn btn-approve" :disabled="actionLoading" @click="doApprove">
            {{ actionLoading ? 'Approving…' : 'Approve QC' }}
          </button>
        </div>
      </template>

      <!-- ── Pending inspection: entry form ── -->
      <template v-else>
        <div class="qc-pending-hint">
          This batch has completed production and is awaiting QC inspection.
          Fill in the checklist below and submit the result.
        </div>

        <!-- Checklist -->
        <div class="qc-checklist">
          <div class="qc-checklist-label">Inspection Checklist</div>
          <div class="qc-check-row editable" v-for="item in qcChecklist" :key="item.key">
            <label class="check-toggle" :class="{ checked: item.passed, failed: !item.passed && item.touched }">
              <input type="checkbox" v-model="item.passed" @change="item.touched = true" />
              <span class="check-box">{{ item.passed ? '✓' : '○' }}</span>
              <span class="check-label">{{ item.label }}</span>
            </label>
          </div>
          <div class="checklist-summary">
            {{ qcChecklist.filter(c => c.passed).length }} / {{ qcChecklist.length }} checks passed
          </div>
        </div>

        <!-- Overall verdict -->
        <div class="qc-verdict-row">
          <div class="verdict-label">Overall Result</div>
          <div class="verdict-options">
            <label class="verdict-opt" :class="{ selected: qcForm.status === 'pass' }">
              <input type="radio" v-model="qcForm.status" value="pass" />
              <span class="verdict-icon">✅</span> Pass
            </label>
            <label class="verdict-opt" :class="{ selected: qcForm.status === 'fail' }">
              <input type="radio" v-model="qcForm.status" value="fail" />
              <span class="verdict-icon">❌</span> Fail
            </label>
          </div>
        </div>

        <!-- Notes -->
        <div class="form-group qc-notes-group">
          <label>Inspector Notes</label>
          <textarea v-model="qcForm.notes" rows="3" placeholder="Describe any defects, measurements, deviations from spec…"></textarea>
        </div>

        <div v-if="qcSubmitError" class="error-msg">{{ qcSubmitError }}</div>

        <div class="qc-submit-row">
          <span class="qc-submit-hint" v-if="qcForm.status === 'fail'">
            Failing will mark batch as QC Failed — it can be re-inspected after rework.
          </span>
          <button
            class="btn btn-qc-submit"
            :class="{ 'btn-pass': qcForm.status === 'pass', 'btn-fail': qcForm.status === 'fail' }"
            :disabled="actionLoading || !qcForm.status"
            @click="submitQc"
          >
            {{ actionLoading ? 'Submitting…' : (qcForm.status === 'pass' ? 'Submit — Pass' : qcForm.status === 'fail' ? 'Submit — Fail' : 'Submit QC') }}
          </button>
        </div>
      </template>
    </div>

    <!-- Complete Batch Modal -->
    <div v-if="showCompleteModal" class="modal-overlay" @click.self="showCompleteModal = false">
      <div class="modal-box">
        <h3>Complete Batch Production</h3>
        <p>Enter the actual quantity produced for <strong>{{ batch.batch_no }}</strong>.</p>
        <div class="form-group">
          <label>Completed Quantity (SQM)</label>
          <input v-model.number="completeQty" type="number" min="0.1" step="0.01" :placeholder="batch.planned_quantity" />
          <span class="hint">Leave blank to use planned quantity ({{ fmtQty(batch.planned_quantity) }} SQM)</span>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showCompleteModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="actionLoading" @click="doComplete">
            {{ actionLoading ? 'Completing…' : 'Complete & Send to QC' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Delete confirm modal -->
    <div v-if="showDeleteModal" class="modal-overlay" @click.self="showDeleteModal = false">
      <div class="modal-box">
        <h3>Delete Batch?</h3>
        <p>This will permanently delete <strong>{{ batch.batch_no }}</strong>.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showDeleteModal = false">Cancel</button>
          <button class="btn btn-danger" :disabled="actionLoading" @click="doDelete">
            {{ actionLoading ? 'Deleting…' : 'Delete' }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <div v-else-if="loading" class="loading-state">Loading batch…</div>
  <div v-else-if="loadError" class="error-banner">{{ loadError }}</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import batchService from '../services/batchService.js'

const props = defineProps({ batchId: { type: Number, required: true } })
const emit  = defineEmits(['back', 'view-order'])

const batch             = ref(null)
const stageProgress     = ref([])
const qc                = ref(null)
const loading           = ref(false)
const loadError         = ref(null)
const loadingProgress   = ref(false)
const saving            = ref(false)
const actionLoading     = ref(false)
const actionError       = ref(null)
const actionSuccess     = ref(null)
const editNotes         = ref('')
const showCompleteModal = ref(false)
const showDeleteModal   = ref(false)
const completeQty       = ref(null)

// Per-stage notes keyed by stage_id
const stageNotes = reactive({})

// ── QC state ────────────────────────────────────────────────────
const qcForm = reactive({ status: '', notes: '' })
const qcSubmitError = ref(null)
const approveNote   = ref('')

// Standard inspection checklist — each item tracks pass/fail + whether touched
const qcChecklist = reactive([
  { key: 'dimensions',   label: 'Panel dimensions within tolerance (±2mm)',            passed: false, touched: false },
  { key: 'foam_density', label: 'Foam density meets spec (±2 kg/m³)',                  passed: false, touched: false },
  { key: 'skin_bond',    label: 'Top & bottom skin bonding — no delamination',         passed: false, touched: false },
  { key: 'surface',      label: 'Surface finish — no dents, scratches or colour defects', passed: false, touched: false },
  { key: 'edges',        label: 'Edge straightness & squareness acceptable',           passed: false, touched: false },
  { key: 'guard_film',   label: 'Guard film applied correctly (if specified)',          passed: true,  touched: false },
  { key: 'marking',      label: 'Panel marking / stacking label present',              passed: false, touched: false },
])

// Live timer state
const now = ref(Date.now())
let _clockTimer   = null
let _refreshTimer = null

// ── Computed ────────────────────────────────────────────────────

const completedStages = computed(() => stageProgress.value.filter(s => s.status === 'completed').length)
const overallPct      = computed(() => {
  const total = stageProgress.value.length
  return total ? Math.round((completedStages.value / total) * 100) : 0
})
const allStagesDone   = computed(() => stageProgress.value.length > 0 && stageProgress.value.every(s => s.status === 'completed'))

// Active stage's live elapsed time
const activeStage     = computed(() => stageProgress.value.find(s => s.status === 'in_progress') ?? null)
const activeStageElapsed = computed(() => {
  if (!activeStage.value?.started_at) return null
  const diffMs  = now.value - new Date(activeStage.value.started_at).getTime()
  const diffMin = Math.floor(diffMs / 60000)
  return formatDuration(diffMin)
})

// Total batch elapsed (started_at → now or completed_at)
const batchElapsed = computed(() => {
  if (!batch.value?.started_at) return null
  const end    = batch.value.completed_at ? new Date(batch.value.completed_at) : new Date(now.value)
  const diffMs = end - new Date(batch.value.started_at)
  const diffMin = Math.floor(diffMs / 60000)
  return formatDuration(diffMin)
})

// ── Data loading ────────────────────────────────────────────────

async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const res   = await batchService.get(props.batchId)
    batch.value = res?.data ?? res
    editNotes.value = batch.value.notes ?? ''
    await loadProgress()
    if (['qc_pending','qc_passed','qc_failed','completed'].includes(batch.value.status)) {
      await loadQc()
    }
    setupTimers()
  } catch (e) {
    loadError.value = e?.response?.data?.message ?? 'Failed to load batch.'
  } finally {
    loading.value = false
  }
}

async function loadProgress() {
  loadingProgress.value = true
  try {
    const res = await batchService.getProgress(props.batchId)
    stageProgress.value = res?.data ?? res ?? []
  } catch { stageProgress.value = [] }
  finally { loadingProgress.value = false }
}

async function loadQc() {
  try {
    const res = await batchService.getQc(props.batchId)
    qc.value  = res?.data ?? res
  } catch {
    qc.value = null
  }
  // Reset form whenever QC state is reloaded
  qcForm.status = ''
  qcForm.notes  = ''
  qcSubmitError.value = null
  approveNote.value   = ''
}

async function submitQc() {
  if (!qcForm.status) return
  actionLoading.value = true
  qcSubmitError.value = null
  try {
    const res = await batchService.createQc(props.batchId, {
      status: qcForm.status,
      notes:  qcForm.notes || null,
    })
    qc.value = res?.data ?? res
    // Reload full batch to update status badge
    const bRes   = await batchService.get(props.batchId)
    batch.value  = bRes?.data ?? bRes
    actionSuccess.value = qcForm.status === 'pass'
      ? 'QC passed — batch is ready for dispatch.'
      : 'QC failed — batch marked for rework.'
  } catch (e) {
    qcSubmitError.value = e?.response?.data?.message ?? 'Failed to submit QC.'
  } finally {
    actionLoading.value = false
  }
}

async function doApprove() {
  if (!qc.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    const res = await batchService.approveQc(qc.value.id, { notes: approveNote.value || null })
    qc.value = res?.data ?? res
    actionSuccess.value = 'QC approved.'
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to approve QC.'
  } finally {
    actionLoading.value = false
  }
}

// ── Timers ──────────────────────────────────────────────────────

function setupTimers() {
  // 1s clock tick for live elapsed display
  if (_clockTimer) clearInterval(_clockTimer)
  _clockTimer = setInterval(() => { now.value = Date.now() }, 1000)

  // Auto-refresh progress every 30s while a stage is active
  if (_refreshTimer) clearInterval(_refreshTimer)
  if (batch.value?.status === 'in_progress') {
    _refreshTimer = setInterval(async () => {
      await loadProgress()
    }, 30000)
  }
}

function clearTimers() {
  if (_clockTimer)   clearInterval(_clockTimer)
  if (_refreshTimer) clearInterval(_refreshTimer)
}

// ── Actions ─────────────────────────────────────────────────────

async function saveNotes() {
  saving.value = true
  try {
    await batchService.update(props.batchId, { notes: editNotes.value })
    actionSuccess.value = 'Notes saved.'
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to save.'
  } finally { saving.value = false }
}

async function doStart() {
  actionLoading.value = true
  actionError.value   = null
  actionSuccess.value = null
  try {
    await batchService.start(props.batchId)
    actionSuccess.value = 'Production started.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to start.'
  } finally { actionLoading.value = false }
}

async function doComplete() {
  actionLoading.value = true
  actionError.value   = null
  try {
    await batchService.complete(props.batchId, {
      completed_quantity: completeQty.value || batch.value.planned_quantity,
    })
    showCompleteModal.value = false
    actionSuccess.value     = 'Batch completed — moved to QC Pending.'
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? 'Failed to complete.'
  } finally { actionLoading.value = false }
}

async function doDelete() {
  actionLoading.value = true
  try {
    await batchService.delete(props.batchId)
    emit('back')
  } catch (e) {
    actionError.value     = e?.response?.data?.message ?? 'Failed to delete.'
    showDeleteModal.value = false
  } finally { actionLoading.value = false }
}

async function startStage(stage) {
  actionLoading.value = true
  actionError.value   = null
  try {
    await batchService.startStage(props.batchId, stage.stage_id, {
      notes: stageNotes[stage.stage_id] || null,
    })
    stageNotes[stage.stage_id] = ''
    actionSuccess.value = `${stage.stage_name} started.`
    await loadProgress()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? e?.message ?? 'Failed to start stage.'
  } finally { actionLoading.value = false }
}

async function completeStage(stage) {
  actionLoading.value = true
  actionError.value   = null
  try {
    // Controller looks up log by batch_id + stage_id — no need to fetch timeline first
    await batchService.completeStage(props.batchId, stage.stage_id, {
      notes: stageNotes[stage.stage_id] || null,
    })
    stageNotes[stage.stage_id] = ''
    actionSuccess.value = `${stage.stage_name} completed.`
    await loadProgress()
  } catch (e) {
    actionError.value = e?.response?.data?.message ?? e?.message ?? 'Failed to complete stage.'
  } finally { actionLoading.value = false }
}

function canStartStage(si) {
  if (si === 0) return true
  return stageProgress.value.slice(0, si).every(s => s.status === 'completed')
}

// ── Formatters ──────────────────────────────────────────────────

function statusLabel(s) {
  return { draft: 'Draft', in_progress: 'In Progress', qc_pending: 'QC Pending', qc_passed: 'QC Passed', qc_failed: 'QC Failed', completed: 'Completed' }[s] ?? s
}
function stageStatusLabel(s) {
  return { pending: 'Pending', in_progress: 'In Progress', completed: 'Done' }[s] ?? s
}
function formatDuration(mins) {
  if (!mins && mins !== 0) return '—'
  if (mins < 1)  return '< 1m'
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function fmtQty(n) { return Number(n || 0).toFixed(2) }

onMounted(load)
onUnmounted(clearTimers)
</script>

<style scoped>
.bd-wrap { font-family: inherit; max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }

.bd-toolbar  { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.bd-title    { display: flex; align-items: center; gap: 8px; flex: 1; }
.batch-no    { font-size: 18px; font-weight: 700; color: var(--primary); font-family: monospace; letter-spacing: 1px; }
.action-bar  { display: flex; gap: 6px; flex-wrap: wrap; }

.error-banner   { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; }
.success-banner { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; padding: 10px 16px; border-radius: 6px; font-size: 13px; }

.card    { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px 24px; }
.card h3 { margin: 0 0 14px; font-size: 15px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }

/* Info grid */
.info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.info-cell { padding: 4px; }
.info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 4px; }
.info-value { font-size: 14px; color: #222; }
.info-value.bold { font-weight: 700; }
.info-sub   { font-size: 11px; color: #888; margin-top: 2px; }
.mono { font-family: monospace; }
.bold { font-weight: 700; }

/* Edit / notes */
.edit-card   { padding: 14px 20px; }
.edit-row    { display: flex; gap: 12px; align-items: flex-end; }
.form-group  { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; }
.form-group input { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.hint        { font-size: 11px; color: #aaa; margin-top: 2px; }
.flex-1      { flex: 1; }
.notes-display { padding: 10px 20px; font-size: 13px; color: #555; background: #fafafa; }
.notes-label   { font-weight: 700; color: #888; text-transform: uppercase; font-size: 11px; margin-right: 6px; }

/* Timeline card header */
.timeline-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }
.timeline-card-header h3 { margin: 0; border: none; padding: 0; font-size: 15px; color: var(--primary); font-weight: 700; }
.btn-refresh { background: var(--primary-tint); border: none; border-radius: 5px; padding: 4px 10px; font-size: 12px; color: var(--primary); cursor: pointer; font-weight: 600; }
.btn-refresh:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-refresh:hover:not(:disabled) { background: var(--primary-bd); }

/* Progress bar */
.progress-bar-wrap { margin-bottom: 20px; }
.progress-track    { height: 8px; background: #e0e0e0; border-radius: 10px; overflow: hidden; margin-bottom: 6px; }
.progress-fill     { height: 100%; background: linear-gradient(90deg, var(--primary), var(--primary-hover)); border-radius: 10px; transition: width 0.5s ease; }
.progress-label    { display: flex; justify-content: space-between; font-size: 12px; color: #666; }
.progress-pct      { font-weight: 700; color: var(--primary); }

/* Stage timeline */
.stage-timeline { display: flex; flex-direction: column; }
.stage-row      { display: flex; }
.stage-connector { display: flex; flex-direction: column; align-items: center; width: 36px; flex-shrink: 0; }
.stage-dot      { width: 20px; height: 20px; border-radius: 50%; border: 2px solid #ddd; background: white; flex-shrink: 0; margin-top: 2px; display: flex; align-items: center; justify-content: center; font-size: 10px; transition: all 0.3s; }
.stage-dot.pending     { border-color: #e0e0e0; background: #fafafa; }
.stage-dot.in_progress { border-color: #2e7d32; background: #e8f5e9; animation: pulse 1.5s ease-in-out infinite; }
.stage-dot.completed   { border-color: var(--primary); background: var(--primary); color: white; }
.dot-check { font-size: 10px; font-weight: 900; line-height: 1; }
.dot-spin  { font-size: 8px; color: #2e7d32; }
.stage-line      { width: 2px; background: #e0e0e0; flex: 1; min-height: 24px; margin: 3px 0; transition: background 0.3s; }
.stage-line.line-done { background: var(--primary); }

@keyframes pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(46,125,50,0.4); }
  50%       { box-shadow: 0 0 0 5px rgba(46,125,50,0); }
}

.stage-content     { flex: 1; padding: 2px 0 22px 14px; }
.stage-header      { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 2px; }
.stage-name        { font-size: 14px; font-weight: 600; color: #222; }
.stage-duration    { font-size: 11px; color: #888; background: #f0f0f0; padding: 2px 8px; border-radius: 8px; font-family: monospace; }
.stage-elapsed     { font-size: 11px; color: #2e7d32; background: #e8f5e9; padding: 2px 8px; border-radius: 8px; font-family: monospace; font-weight: 700; }
.stage-meta        { font-size: 11px; color: #888; margin-top: 2px; }
.stage-notes-display { font-size: 12px; color: #555; background: #fffde7; border-left: 3px solid #ffc107; padding: 4px 8px; margin-top: 5px; border-radius: 0 4px 4px 0; }
.notes-icon        { margin-right: 4px; }

.stage-status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.stage-status-badge.pending     { background: #f5f5f5; color: #aaa; }
.stage-status-badge.in_progress { background: #e8f5e9; color: #2e7d32; }
.stage-status-badge.completed   { background: var(--primary-tint); color: var(--primary); }

/* Stage action area */
.stage-actions     { margin-top: 8px; }
.stage-action-row  { display: flex; gap: 8px; align-items: center; }
.stage-note-input  { flex: 1; max-width: 280px; padding: 5px 9px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; color: #444; }
.stage-note-input:disabled { background: #f5f5f5; color: #aaa; }
.locked-hint       { font-size: 11px; color: #aaa; font-style: italic; margin-top: 3px; }

.btn-stage         { padding: 5px 14px; border: none; border-radius: 5px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-stage-start   { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
.btn-stage-start:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-stage-done    { background: var(--primary); color: white; }
.btn-stage-done:disabled { opacity: 0.5; cursor: not-allowed; }

.all-done-hint  { margin-top: 14px; padding: 12px 16px; background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; font-size: 14px; color: #2e7d32; font-weight: 500; }
.loading-hint   { color: #aaa; font-size: 13px; padding: 10px 0; }

/* Panel items */
.item-block   { border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 14px; margin-bottom: 10px; background: #fafafe; }
.item-header  { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 6px; }
.item-num     { background: var(--primary); color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
.item-spec    { flex: 1; }
.spec-title   { font-weight: 700; font-size: 13px; color: var(--primary); }
.spec-detail  { font-size: 11px; color: #666; margin-top: 3px; line-height: 1.6; }
.item-total   { text-align: right; flex-shrink: 0; }
.item-sqm     { font-size: 13px; font-weight: 700; color: var(--primary); }

.size-table   { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
.size-table th { background: var(--primary-tint); color: #333; padding: 4px 8px; border: 1px solid var(--primary-bd); font-size: 10px; text-transform: uppercase; }
.size-table td { padding: 4px 8px; border: 1px solid #e0e0e0; }
.text-center  { text-align: center; }
.warn-tag     { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; border-radius: 3px; padding: 1px 4px; font-size: 9px; font-weight: 700; margin-left: 4px; }

/* QC card */
.qc-card { }

.qc-result-bar { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 18px; font-weight: 800; letter-spacing: 1px; }
.qc-result-bar.pass { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
.qc-result-bar.fail { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
.qc-result-icon { font-size: 22px; }
.qc-result-label { font-size: 16px; }

.qc-detail-grid  { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
.qc-detail-cell  { padding: 4px; }
.qc-detail-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #888; margin-bottom: 3px; }
.qc-detail-value { font-size: 13px; color: #333; font-weight: 500; }

.qc-pending-hint { background: #fff8e1; border: 1px solid #ffe082; border-radius: 7px; padding: 11px 14px; font-size: 13px; color: #6d4c00; margin-bottom: 18px; }

/* Checklist */
.qc-checklist       { margin-bottom: 20px; }
.qc-checklist-results { margin-bottom: 16px; }
.qc-checklist-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #888; letter-spacing: 0.5px; margin-bottom: 10px; }
.qc-check-row       { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f5f5f5; }
.qc-check-row:last-child { border-bottom: none; }
.qc-check-row.editable { cursor: pointer; }

.check-icon  { font-size: 14px; font-weight: 700; width: 20px; text-align: center; flex-shrink: 0; }
.check-icon.pass { color: #2e7d32; }
.check-icon.fail { color: #c62828; }
.check-label { font-size: 13px; color: #444; }

.check-toggle        { display: flex; align-items: center; gap: 10px; cursor: pointer; width: 100%; }
.check-toggle input  { display: none; }
.check-box           { width: 22px; height: 22px; border: 2px solid #ddd; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; transition: all 0.15s; }
.check-toggle.checked .check-box { background: var(--primary); border-color: var(--primary); color: white; }
.check-toggle.failed .check-box  { border-color: #ef9a9a; }
.check-toggle:hover .check-box   { border-color: var(--primary); }

.checklist-summary { font-size: 12px; color: #888; font-style: italic; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e0e0e0; }

/* Verdict */
.qc-verdict-row  { display: flex; align-items: center; gap: 20px; margin-bottom: 16px; flex-wrap: wrap; }
.verdict-label   { font-size: 13px; font-weight: 700; color: #333; min-width: 120px; }
.verdict-options { display: flex; gap: 12px; }
.verdict-opt     { display: flex; align-items: center; gap: 8px; padding: 8px 18px; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.15s; }
.verdict-opt input  { display: none; }
.verdict-opt.selected[class*="pass"], .verdict-opt:has(input[value="pass"]).selected { border-color: #2e7d32; background: #e8f5e9; color: #2e7d32; }
.verdict-opt.selected { border-color: var(--primary); background: var(--primary-tint); }
.verdict-opt:has(input[value="pass"]).selected { border-color: #2e7d32; background: #e8f5e9; color: #2e7d32; }
.verdict-opt:has(input[value="fail"]).selected { border-color: #c62828; background: #ffebee; color: #c62828; }
.verdict-icon { font-size: 16px; }

/* QC form */
.qc-notes-group textarea { width: 100%; padding: 8px 11px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; resize: vertical; box-sizing: border-box; }
.qc-notes-group textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }
.qc-notes-block   { margin-top: 12px; }
.qc-notes-text    { font-size: 13px; color: #555; background: #fafafa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px 12px; line-height: 1.6; }

.qc-submit-row   { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #f0f0f0; flex-wrap: wrap; }
.qc-submit-hint  { font-size: 12px; color: #c62828; font-style: italic; flex: 1; }
.btn-qc-submit   { padding: 9px 22px; border: none; border-radius: 7px; font-size: 14px; font-weight: 700; cursor: pointer; }
.btn-qc-submit:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-pass        { background: #2e7d32; color: white; }
.btn-fail        { background: #c62828; color: white; }
.btn-qc-submit:not(.btn-pass):not(.btn-fail) { background: var(--primary); color: white; }

.qc-approve-row  { display: flex; align-items: center; gap: 10px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #f0f0f0; }
.qc-note-input   { flex: 1; padding: 7px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.btn-approve     { background: var(--primary); color: white; padding: 7px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-approve:disabled { opacity: 0.5; cursor: not-allowed; }

.error-msg  { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 6px; font-size: 13px; margin-top: 12px; }
.empty-hint { text-align: center; color: #aaa; font-style: italic; padding: 14px; border: 2px dashed #e0e0e0; border-radius: 7px; }

/* Status badges */
.status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.status-badge.draft       { background: #f3f4f6; color: #6b7280; border: 1px solid #e0e0e0; }
.status-badge.in_progress { background: #e8f5e9; color: #2e7d32; }
.status-badge.qc_pending  { background: #fff3e0; color: #e65100; }
.status-badge.qc_passed   { background: var(--primary-tint); color: var(--primary); }
.status-badge.qc_failed   { background: #ffebee; color: #c62828; }
.status-badge.completed   { background: #e0f2f1; color: #00695c; }

/* Buttons */
.btn          { padding: 7px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-ghost    { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-save     { background: var(--primary); color: white; flex-shrink: 0; }
.btn-start    { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
.btn-complete { background: var(--primary); color: white; }
.btn-danger   { background: #c62828; color: white; }
.btn-primary  { background: var(--primary); color: white; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.loading-state { text-align: center; padding: 60px; color: #888; }

/* Modals */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-box     { background: white; border-radius: 10px; padding: 28px 32px; min-width: 360px; max-width: 480px; box-shadow: 0 8px 40px rgba(0,0,0,0.2); }
.modal-box h3  { margin: 0 0 10px; color: var(--primary); font-size: 16px; }
.modal-box p   { color: #555; font-size: 14px; margin-bottom: 16px; line-height: 1.6; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
</style>
