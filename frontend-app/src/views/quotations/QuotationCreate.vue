<template>
  <div class="boq-create">
    <div class="page-header">
      <div>
        <button class="btn btn-outline btn-sm" @click="$router.back()">← Back</button>
        <div class="page-title" style="margin-top:8px">
          {{ isEdit ? `Edit BOQ ${form.quotation_no}` : 'New BOQ / Quotation' }}
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-outline" @click="saveDraft" :disabled="saving">💾 Save Draft</button>
        <button v-if="form.id" class="btn btn-outline" @click="previewPdf">👁 Preview PDF</button>
        <button v-if="form.id" class="btn btn-success" @click="sendQuotation" :disabled="saving">✉️ Send to Customer</button>
      </div>
    </div>

    <!-- ── HEADER ─────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header"><div class="card-title">BOQ Header</div>
        <div v-if="form.quotation_no" style="font-size:13px;color:#888">{{ form.quotation_no }}</div>
      </div>
      <div class="form-row-3">
        <div class="form-group">
          <label class="form-label">Customer <span style="color:red">*</span></label>
          <div style="display:flex;gap:6px">
            <select v-model="form.customer_id" class="form-control" @change="onCustomerChange">
              <option value="">— Select Customer —</option>
              <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.state_code }})</option>
            </select>
            <button class="btn btn-outline btn-sm" @click="showAddCustomer=true" title="Add new customer">+</button>
          </div>
          <div v-if="gstInfo" style="font-size:11px;margin-top:4px;padding:4px 6px;background:#e3f2fd;border-radius:3px;color:#1565c0">
            {{ gstInfo }}
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Project Name</label>
          <input v-model="form.project_name" class="form-control" placeholder="e.g. Cold Storage Phase 2" />
        </div>
        <div class="form-group">
          <label class="form-label">Project Location</label>
          <input v-model="form.project_location" class="form-control" placeholder="City, State" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Quality Grade</label>
          <div style="display:flex;gap:20px;padding-top:9px">
            <label v-for="g in ['High','Medium','Standard']" :key="g" class="radio-label">
              <input type="radio" v-model="form.quality_grade" :value="g" @change="onQualityChange" /> {{ g }}
            </label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Validity (days)</label>
          <input v-model.number="form.validity_days" type="number" min="1" max="365" class="form-control" style="max-width:120px" />
        </div>
        <div class="form-group">
          <label class="form-label">BOQ Date</label>
          <input v-model="form.quoted_on" type="date" class="form-control" style="max-width:180px" />
        </div>
        <div class="form-group">
          <label class="form-label">BOQ Prefix</label>
          <input v-model="form.quotation_prefix" class="form-control" style="max-width:100px" maxlength="10" />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Notes / Terms</label>
        <textarea v-model="form.notes" class="form-control" rows="2" placeholder="Payment terms, delivery conditions…"></textarea>
      </div>
    </div>

    <!-- ── PANEL ROWS ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Panel Items</div>
        <button class="btn btn-primary btn-sm" @click="addPanelRow">+ Add Panel Row</button>
      </div>

      <div v-if="!form.panelRows.length" class="empty-state" style="padding:30px">
        <div class="icon">📐</div>
        <p>No panel rows yet. Click "+ Add Panel Row" to start.</p>
      </div>

      <div v-for="(row, idx) in form.panelRows" :key="row._key" class="panel-row-card">
        <div class="panel-row-header">
          <span class="row-badge">Row {{ idx + 1 }}</span>
          <span v-if="row.total_sqm > 0" style="font-size:13px;color:#555">
            {{ row.total_sqm.toFixed(2) }} SQM — ₹{{ rowTotalAmount(row).toFixed(0) }}
          </span>
          <div style="display:flex;gap:6px">
            <button class="btn btn-outline btn-sm" @click="duplicatePanelRow(idx)" title="Duplicate this row">⊕ Dup</button>
            <button class="btn btn-danger btn-sm" @click="removePanelRow(idx)">✕</button>
          </div>
        </div>

        <!-- Skin & Panel Spec — 3 columns -->
        <div class="form-row-3">
          <div class="form-group">
            <label class="form-label">Panel Type <span style="color:red">*</span></label>
            <select v-model="row.panel_type_id" class="form-control" @change="onPanelTypeChange(row)">
              <option value="">— Select —</option>
              <option v-for="pt in panelTypes" :key="pt.id" :value="pt.id">{{ pt.name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Thickness (mm) <span style="color:red">*</span></label>
            <select v-model.number="row.thickness" class="form-control" @change="fetchSuggestedRate(row)">
              <option value="">—</option>
              <option v-for="t in thicknessOptions" :key="t" :value="t">{{ t }} mm</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Density Type</label>
            <select v-model="row.density_type" class="form-control" @change="onDensityTypeChange(row); fetchSuggestedRate(row)">
              <option value="PUF">PUF</option>
              <option value="PIR">PIR</option>
            </select>
          </div>
        </div>

        <div class="form-row-3">
          <div class="form-group">
            <label class="form-label">Density (kg/m³)</label>
            <select v-model.number="row.density_kgm3" class="form-control">
              <option v-for="d in densityOptions(row.density_type)" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Top Skin Material <span style="color:red">*</span></label>
            <select v-model="row.top_skin_material" class="form-control">
              <option v-for="m in skinMaterials" :key="m">{{ m }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Top Skin Thickness <span style="color:red">*</span></label>
            <select v-model.number="row.top_skin_thickness" class="form-control" @change="fetchSuggestedRate(row)">
              <option v-for="t in skinThicknesses" :key="t" :value="t">{{ t }} mm</option>
            </select>
          </div>
        </div>

        <div class="form-row-3">
          <div class="form-group">
            <label class="form-label">Top Color</label>
            <input v-model="row.top_color" class="form-control" placeholder="Off White" list="color-list" />
          </div>
          <div class="form-group">
            <label class="form-label">Top Surface</label>
            <select v-model="row.top_surface" class="form-control" @change="row._surfaceManuallyChanged=true; fetchSuggestedRate(row)">
              <option value="RIBBED">RIBBED</option>
              <option value="PLAIN">PLAIN</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Guard Film &nbsp; Cello Tap</label>
            <div style="display:flex;gap:20px;padding-top:10px">
              <label class="radio-label"><input type="checkbox" v-model="row.guard_film" /> Guard Film</label>
              <label class="radio-label"><input type="checkbox" v-model="row.cello_tap" /> Cello Tap</label>
            </div>
          </div>
        </div>

        <div class="form-row-3">
          <div class="form-group">
            <label class="form-label">Bottom Skin Material <span style="color:red">*</span></label>
            <select v-model="row.bottom_skin_material" class="form-control">
              <option v-for="m in skinMaterials" :key="m">{{ m }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Bottom Skin Thickness</label>
            <select v-model.number="row.bottom_skin_thickness" class="form-control">
              <option v-for="t in skinThicknesses" :key="t" :value="t">{{ t }} mm</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Bottom Color</label>
            <input v-model="row.bottom_color" class="form-control" placeholder="Off White" list="color-list" />
          </div>
        </div>
        <p style="font-size:11px;color:#888;margin-bottom:12px">Bottom Surface is always PLAIN. HSN: {{ row.hsn_code || '39259010' }}</p>

        <!-- Size rows sub-table -->
        <div class="sizes-section">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-weight:600;font-size:13px;color:#333">📏 Size Breakdown</span>
            <button class="btn btn-outline btn-sm" @click="addSizeRow(row)">+ Add Size</button>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Length (mm)</th>
                  <th>Width (mm)</th>
                  <th>Nos</th>
                  <th>SQM</th>
                  <th>Rate (₹/SQM)</th>
                  <th>Amount (₹)</th>
                  <th>HSN</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(sr, si) in row.sizes" :key="si">
                  <td>
                    <input v-model.number="sr.length_mm" type="number" class="form-control"
                      min="500" max="14000" style="width:110px" @input="calcSize(sr)"
                      :style="sr.length_mm && sr.length_mm < 2000 ? 'border-color:orange' : ''" />
                    <div v-if="sr.length_mm && sr.length_mm < 2000" style="color:orange;font-size:11px;margin-top:2px">
                      ⚠️ Will produce at doubled length
                    </div>
                  </td>
                  <td><input type="number" value="1000" readonly class="form-control" style="width:80px;background:#f5f5f5" /></td>
                  <td><input v-model.number="sr.nos" type="number" min="1" class="form-control" style="width:80px" @input="calcSize(sr)" /></td>
                  <td style="font-weight:700;color:var(--primary)">{{ (sr.sqm||0).toFixed(2) }}</td>
                  <td>
                    <input v-model.number="sr.rate_per_sqm" type="number" class="form-control" style="width:110px" @input="calcSize(sr)" />
                    <div v-if="row._suggestedRate && sr.rate_per_sqm > 0 && sr.rate_per_sqm < row._suggestedRate * 0.85"
                        style="color:orange;font-size:11px;margin-top:2px">
                      ⚠️ Below standard (₹{{ row._suggestedRate }}/SQM)
                    </div>
                  </td>
                  <td style="font-weight:600">₹{{ (sr.amount||0).toFixed(0) }}</td>
                  <td style="font-size:11px;color:#888">{{ row.hsn_code || '39259010' }}</td>
                  <td><button class="btn btn-danger btn-sm" @click="row.sizes.splice(si,1);calcRowTotals(row);calcTotals()">✕</button></td>
                </tr>
                <tr v-if="!row.sizes.length">
                  <td colspan="8" style="text-align:center;color:#999;padding:12px">Add size rows above</td>
                </tr>
              </tbody>
              <tfoot v-if="row.sizes.length">
                <tr style="background:#f0f4ff;font-weight:700">
                  <td colspan="3" style="text-align:right;padding:8px 12px">Row Total:</td>
                  <td>{{ row.total_sqm?.toFixed(2) ?? '0.00' }} SQM</td>
                  <td></td>
                  <td>₹{{ rowTotalAmount(row).toFixed(0) }}</td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div v-if="row._suggestedRate" style="font-size:12px;color:#1976d2;margin-top:6px;padding:4px 8px;background:#e3f2fd;border-radius:3px">
            💡 Suggested rate for this configuration: <strong>₹{{ row._suggestedRate }}/SQM</strong>
            <button class="btn btn-sm btn-outline" style="margin-left:8px;padding:2px 8px;font-size:11px" @click="applyRate(row)">Apply to all rows</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── ACCESSORIES ────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Accessories & Installation</div>
        <div style="display:flex;gap:6px">
          <button class="btn btn-outline btn-sm" @click="addAccessoryRow('standard')">+ Accessory</button>
          <button class="btn btn-outline btn-sm" @click="addAccessoryRow('door')">+ Door/Window</button>
          <button class="btn btn-outline btn-sm" @click="addAccessoryRow('installation')">+ Installation</button>
          <button class="btn btn-outline btn-sm" @click="addAccessoryRow('custom')">+ Custom</button>
        </div>
      </div>

      <div v-if="form.accessories.length" class="table-wrap">
        <table>
          <thead>
            <tr><th>Type</th><th>Accessory / Description</th><th>Unit</th><th>Qty</th><th>Rate</th><th>Amount</th><th>HSN</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="(acc, ai) in form.accessories" :key="ai" :style="acc.type==='installation'?'background:#f9fff9':acc.type==='door'?'background:#fffbf0':''">
              <td style="font-size:11px;white-space:nowrap">
                <span class="badge" :class="typeBadge(acc.type)">{{ acc.type }}</span>
              </td>
              <td>
                <select v-if="acc.type==='standard'" v-model="acc.accessory_id" class="form-control" style="min-width:160px" @change="onAccessoryChange(acc)">
                  <option value="">— Select —</option>
                  <option v-for="a in accessories" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
                <template v-else-if="acc.type==='door'">
                  <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <input v-model="acc.description" class="form-control" placeholder="Description" style="min-width:120px" />
                    <input v-model.number="acc.door_width" type="number" class="form-control" placeholder="W mm" style="width:80px" />
                    <span style="padding-top:8px">×</span>
                    <input v-model.number="acc.door_height" type="number" class="form-control" placeholder="H mm" style="width:80px" />
                    <select v-model="acc.door_type" class="form-control" style="width:100px">
                      <option value="Single">Single</option><option value="Double">Double</option>
                    </select>
                  </div>
                  <div style="display:flex;gap:8px;margin-top:4px;font-size:12px">
                    <label><input type="checkbox" v-model="acc.has_hinges" /> Hinges</label>
                    <label><input type="checkbox" v-model="acc.has_lock" /> Lock</label>
                    <label><input type="checkbox" v-model="acc.has_handle" /> Handle</label>
                    <label><input type="checkbox" v-model="acc.has_closer" /> Closer</label>
                  </div>
                </template>
                <input v-else v-model="acc.description" class="form-control" style="min-width:180px"
                  :placeholder="acc.type==='installation'?'Installation Charges':'Custom item description'" />
              </td>
              <td><input v-model="acc.unit" class="form-control" style="width:70px" /></td>
              <td><input v-model.number="acc.qty" type="number" min="0" class="form-control" style="width:80px" @input="calcAcc(acc)" /></td>
              <td><input v-model.number="acc.rate" type="number" min="0" class="form-control" style="width:100px" @input="calcAcc(acc)" /></td>
              <td style="font-weight:600">₹{{ (acc.amount||0).toFixed(0) }}</td>
              <td style="font-size:11px;color:#888">{{ acc.hsn_code || '' }}</td>
              <td><button class="btn btn-danger btn-sm" @click="form.accessories.splice(ai,1);calcTotals()">✕</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="text-muted" style="padding:16px">No accessories added.</div>
    </div>

    <!-- ── SUMMARY ────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header"><div class="card-title">BOQ Summary</div>
        <div style="font-size:13px;color:#888">Total: <strong>{{ totals.totalSqm.toFixed(2) }} SQM</strong></div>
      </div>
      <div style="max-width:520px;margin-left:auto">
        <div class="sum-line"><span>Panel Subtotal</span><span>₹{{ totals.panelSubtotal.toFixed(2) }}</span></div>
        <div class="sum-line"><span>Accessories</span><span>₹{{ totals.accessorySubtotal.toFixed(2) }}</span></div>
        <div class="sum-line"><span>Installation</span><span>₹{{ totals.installation.toFixed(2) }}</span></div>
        <div class="sum-line subtotal"><span>Subtotal</span><span>₹{{ totals.subtotal.toFixed(2) }}</span></div>
        <div class="sum-line">
          <span>Discount <input v-model.number="form.discount_pct" type="number" min="0" max="100" class="form-control" style="width:60px;display:inline-block;margin:0 4px" @input="calcTotals" /> %</span>
          <span style="color:var(--danger)">- ₹{{ totals.discountAmt.toFixed(2) }}</span>
        </div>
        <div class="sum-line subtotal"><span>Taxable Amount</span><span>₹{{ totals.taxableAmt.toFixed(2) }}</span></div>
        <div class="sum-line"><span>GST @18% <span style="font-size:11px;color:#888">({{ gstLabel }})</span></span><span>₹{{ totals.gst.toFixed(2) }}</span></div>
        <div v-if="totals.cgst>0" class="sum-line" style="font-size:12px;color:#888;padding-left:20px"><span>CGST 9%</span><span>₹{{ totals.cgst.toFixed(2) }}</span></div>
        <div v-if="totals.sgst>0" class="sum-line" style="font-size:12px;color:#888;padding-left:20px"><span>SGST 9%</span><span>₹{{ totals.sgst.toFixed(2) }}</span></div>
        <div v-if="totals.igst>0" class="sum-line" style="font-size:12px;color:#888;padding-left:20px"><span>IGST 18%</span><span>₹{{ totals.igst.toFixed(2) }}</span></div>
        <div class="sum-line">
          <span>
            Transportation
            <label style="margin-left:8px;font-size:12px;cursor:pointer"><input type="checkbox" v-model="form.transport_fixed" @change="calcTotals" /> Fixed</label>
            <input v-if="form.transport_fixed" v-model.number="form.transport_amount" type="number" class="form-control" style="width:110px;display:inline-block;margin-left:8px" @input="calcTotals" />
            <span v-else style="font-size:12px;color:#888;margin-left:6px">Extra as Actual</span>
          </span>
          <span>{{ form.transport_fixed ? '₹'+form.transport_amount : 'As Actual' }}</span>
        </div>
        <div class="sum-line"><span>Round Off</span><span>₹{{ totals.roundOff.toFixed(2) }}</span></div>
        <div class="sum-line grand"><span>GRAND TOTAL</span><span>₹{{ totals.grandTotal.toFixed(2) }}</span></div>
        <div class="sum-line">
          <span>Advance <input v-model.number="form.advance_pct" type="number" min="0" max="100" class="form-control" style="width:60px;display:inline-block;margin:0 4px" @input="calcTotals" /> %</span>
          <span>₹{{ totals.advance.toFixed(2) }}</span>
        </div>
        <div class="sum-line"><span>Balance Due</span><span style="color:var(--primary);font-weight:700">₹{{ totals.balance.toFixed(2) }}</span></div>
      </div>
    </div>

    <div v-if="errorMsg" class="alert alert-error" style="margin-top:16px">{{ errorMsg }}</div>
    <div v-if="successMsg" class="alert alert-success" style="margin-top:16px">{{ successMsg }}</div>

    <!-- datalist for colors -->
    <datalist id="color-list">
      <option v-for="c in colorOptions" :key="c" :value="c" />
    </datalist>

    <!-- Quick-add Customer Modal -->
    <div v-if="showAddCustomer" class="modal-overlay" @click.self="showAddCustomer=false">
      <div class="modal" style="max-width:640px">
        <div class="modal-header"><div class="modal-title">Add New Customer</div><button class="modal-close" @click="showAddCustomer=false">✕</button></div>
        <div class="modal-body">
          <div class="form-row"><div class="form-group"><label class="form-label">Company Name *</label><input v-model="newCust.name" class="form-control" /></div><div class="form-group"><label class="form-label">Contact Person</label><input v-model="newCust.contact_person" class="form-control" /></div></div>
          <div class="form-row"><div class="form-group"><label class="form-label">Email</label><input v-model="newCust.email" class="form-control" type="email" /></div><div class="form-group"><label class="form-label">Phone</label><input v-model="newCust.phone" class="form-control" /></div></div>
          <div class="form-row"><div class="form-group"><label class="form-label">City</label><input v-model="newCust.city" class="form-control" /></div><div class="form-group"><label class="form-label">State</label><input v-model="newCust.state" class="form-control" /></div><div class="form-group"><label class="form-label">State Code</label><input v-model="newCust.state_code" class="form-control" maxlength="2" placeholder="MH" /></div></div>
          <div class="form-row"><div class="form-group"><label class="form-label">GSTIN</label><input v-model="newCust.gstin" class="form-control" placeholder="27XXXXX1234X1ZX" /></div><div class="form-group"><label class="form-label">Type</label><select v-model="newCust.type" class="form-control"><option>retail</option><option>wholesale</option><option>distributor</option><option>corporate</option></select></div></div>
          <div v-if="custError" class="alert alert-error">{{ custError }}</div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline" @click="showAddCustomer=false">Cancel</button><button class="btn btn-primary" @click="saveNewCustomer" :disabled="saving">Save Customer</button></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'

const router = useRouter()
const route  = useRoute()
const isEdit = computed(() => !!route.params.id)

const saving = ref(false)
const errorMsg  = ref('')
const successMsg = ref('')
const showAddCustomer = ref(false)
const custError = ref('')
const customers   = ref([])
const panelTypes  = ref([])
const accessories = ref([])

const COMPANY_STATE = ref('GJ') // Will be loaded from /auth/me

const thicknessOptions = [30, 40, 50, 60, 75, 80, 100, 120, 150, 200]
const skinMaterials    = ['PPGI', 'PPGL', 'SS304', 'GI', 'Aluminium']
const skinThicknesses  = [0.30, 0.35, 0.40, 0.45, 0.50, 0.60]
const colorOptions     = ['Off White', 'White', 'Ivory', 'Cream', 'Sky Blue', 'Grey', 'Dark Green', 'Red Oxide', 'Custom']
const densityOptions   = (type) => type === 'PIR' ? [40, 42, 48] : [38, 40, 42]
const panelCategoryMap = {
  roof:      { top: 'RIBBED', bottom: 'PLAIN' },
  wall:      { top: 'PLAIN',  bottom: 'PLAIN' },
  ceiling:   { top: 'PLAIN',  bottom: 'PLAIN' },
  cold_room: { top: 'PLAIN',  bottom: 'PLAIN' },
}

// GST state detection
const isInterState = computed(() => {
  const cust = customers.value.find(c => c.id == form.customer_id)
  if (!cust || !cust.state_code) return false
  return cust.state_code.toUpperCase() !== COMPANY_STATE.value.toUpperCase()
})

const gstLabel = computed(() => isInterState.value ? 'IGST 18%' : 'CGST 9% + SGST 9%')

const gstInfo = computed(() => {
  const cust = customers.value.find(c => c.id == form.customer_id)
  if (!cust || !cust.state_code) return null
  if (isInterState.value) return `Inter-state (${COMPANY_STATE.value} → ${cust.state_code}) — IGST applies`
  return `Intra-state (${cust.state_code}) — CGST + SGST applies`
})

// Form state
const form = reactive({
  id: null, quotation_no: '', quotation_prefix: 'SCP',
  customer_id: '', project_name: '', project_location: '',
  quality_grade: 'High', validity_days: 10,
  quoted_on: new Date().toISOString().split('T')[0],
  discount_pct: 0, transport_fixed: false, transport_amount: 0,
  advance_pct: 50, notes: '',
  panelRows: [], accessories: [],
})

const newCust = reactive({ name:'', contact_person:'', email:'', phone:'', city:'', state:'', state_code:'', gstin:'', type:'retail' })

// Totals
const totals = reactive({
  totalSqm: 0, panelSubtotal: 0, accessorySubtotal: 0, installation: 0,
  subtotal: 0, discountAmt: 0, taxableAmt: 0,
  gst: 0, cgst: 0, sgst: 0, igst: 0,
  transport: 0, roundOff: 0, grandTotal: 0, advance: 0, balance: 0,
})

// ── Row management ──────────────────────────────────────────────────────

let rowKey = 0
const newPanelRow = () => ({
  _key: rowKey++, _surfaceManuallyChanged: false, _suggestedRate: null,
  panel_type_id: '', thickness: '', density_type: 'PUF', density_kgm3: 38,
  top_skin_material: 'PPGI', top_skin_thickness: 0.40, top_color: 'Off White', top_surface: 'PLAIN',
  bottom_skin_material: 'PPGI', bottom_skin_thickness: 0.40, bottom_color: 'Off White',
  guard_film: false, cello_tap: false, hsn_code: '39259010',
  total_sqm: 0, sizes: [],
})
const newSizeRow = (suggestedRate = 0) => ({ length_mm: null, width_mm: 1000, nos: null, sqm: 0, rate_per_sqm: suggestedRate, amount: 0 })

const addPanelRow = () => { form.panelRows.push(newPanelRow()); calcTotals() }
const removePanelRow = (i) => { form.panelRows.splice(i, 1); calcTotals() }
const duplicatePanelRow = (i) => {
  const src = form.panelRows[i]
  const copy = { ...newPanelRow(), ...JSON.parse(JSON.stringify(src)), _key: rowKey++ }
  copy.sizes = src.sizes.map(s => ({ ...s }))
  form.panelRows.splice(i + 1, 0, copy)
  calcTotals()
}
const addSizeRow = (row) => { row.sizes.push(newSizeRow(row._suggestedRate || 1050)); calcTotals() }

const onPanelTypeChange = (row) => {
  const pt = panelTypes.value.find(p => p.id == row.panel_type_id)
  if (pt) {
    if (!row._surfaceManuallyChanged) {
      // Use category from API — roof = RIBBED, everything else = PLAIN
      row.top_surface = (pt.category === 'roof') ? 'RIBBED' : 'PLAIN'
    }
    row.hsn_code = pt.hsn_code || '39259010'
    // Update available thicknesses from panel type if defined
    if (pt.available_thicknesses?.length) {
      row._availableThicknesses = pt.available_thicknesses
      if (!pt.available_thicknesses.includes(row.thickness)) {
        row.thickness = pt.available_thicknesses[Math.floor(pt.available_thicknesses.length / 2)]
      }
    } else {
      row._availableThicknesses = null
    }
  }
  fetchSuggestedRate(row)
}

const onDensityTypeChange = (row) => {
  const opts = densityOptions(row.density_type)
  if (!opts.includes(row.density_kgm3)) row.density_kgm3 = opts[0]
}

const onCustomerChange = () => calcTotals()
const onQualityChange  = () => form.panelRows.forEach(r => fetchSuggestedRate(r))

const fetchSuggestedRate = async (row) => {
  if (!row.panel_type_id || !form.customer_id || !row.thickness) return
  try {
    const { data } = await api.post('/quotations/suggested-rate', {
      panel_type_id:      row.panel_type_id,
      customer_id:        form.customer_id,
      quality_grade:      form.quality_grade,
      thickness:          row.thickness,
      density_type:       row.density_type,
      top_skin_thickness: row.top_skin_thickness,
      top_surface:        row.top_surface,
    })
    row._suggestedRate = data.data?.rate ?? null
  } catch {}
}

const applyRate = (row) => {
  if (!row._suggestedRate) return
  row.sizes.forEach(sr => { sr.rate_per_sqm = row._suggestedRate; calcSize(sr) })
  calcRowTotals(row); calcTotals()
}

// ── Calculations ────────────────────────────────────────────────────────

const calcSize = (sr) => {
  sr.sqm    = sr.length_mm && sr.nos ? (sr.length_mm / 1000) * 1 * sr.nos : 0 // width always 1000mm
  sr.amount = sr.sqm * (sr.rate_per_sqm || 0)
  calcTotals()
}

const calcRowTotals = (row) => {
  row.total_sqm = row.sizes.reduce((s, sr) => s + (sr.sqm || 0), 0)
}

const rowTotalAmount = (row) => row.sizes.reduce((s, sr) => s + (sr.amount || 0), 0)

const calcAcc = (acc) => { acc.amount = (acc.qty || 0) * (acc.rate || 0); calcTotals() }

const calcTotals = () => {
  form.panelRows.forEach(r => calcRowTotals(r))

  totals.totalSqm        = form.panelRows.reduce((s, r) => s + (r.total_sqm || 0), 0)
  totals.panelSubtotal   = form.panelRows.reduce((s, r) => s + rowTotalAmount(r), 0)
  totals.accessorySubtotal = form.accessories.filter(a => a.type !== 'installation').reduce((s, a) => s + (a.amount || 0), 0)
  totals.installation    = form.accessories.filter(a => a.type === 'installation').reduce((s, a) => s + (a.amount || 0), 0)
  totals.subtotal        = totals.panelSubtotal + totals.accessorySubtotal + totals.installation
  totals.discountAmt     = totals.subtotal * (form.discount_pct || 0) / 100
  totals.taxableAmt      = totals.subtotal - totals.discountAmt
  totals.gst             = totals.taxableAmt * 0.18
  if (isInterState.value) {
    totals.igst = totals.gst; totals.cgst = 0; totals.sgst = 0
  } else {
    totals.cgst = totals.gst / 2; totals.sgst = totals.gst / 2; totals.igst = 0
  }
  const transport = form.transport_fixed ? (form.transport_amount || 0) : 0
  const raw = totals.taxableAmt + totals.gst + transport
  totals.roundOff    = Math.round(raw) - raw
  totals.grandTotal  = raw + totals.roundOff
  totals.advance     = totals.grandTotal * (form.advance_pct || 50) / 100
  totals.balance     = totals.grandTotal - totals.advance
}

// ── Accessories ─────────────────────────────────────────────────────────

const hsnByType = { standard: '73089090', door: '73089090', installation: '994568', custom: '' }
const typeBadge = (t) => ({ standard:'badge-sent', door:'badge-warning', installation:'badge-success', custom:'badge-draft' }[t] || 'badge-draft')

const addAccessoryRow = (type) => {
  form.accessories.push({
    type, accessory_id: '', description: '', unit: type === 'door' ? 'NOS' : (type === 'installation' ? 'LS' : 'NOS'),
    qty: 0, rate: 0, amount: 0, hsn_code: hsnByType[type] || '',
    // door-specific
    door_width: null, door_height: null, door_type: 'Single',
    has_hinges: true, has_lock: true, has_handle: true, has_closer: false,
  })
}

const onAccessoryChange = (acc) => {
  const master = accessories.value.find(a => a.id == acc.accessory_id)
  if (master) {
    acc.description = master.name
    acc.unit        = master.unit || 'NOS'
    acc.hsn_code    = master.hsn_code || '73089090'
    acc.rate        = master.rate || master.unit_price || 0
    calcAcc(acc)
  }
}

// ── Save & Actions ──────────────────────────────────────────────────────

const buildPayload = () => ({
  customer_id:      form.customer_id,
  project_name:     form.project_name,
  project_location: form.project_location,
  quality_grade:    form.quality_grade,
  validity_days:    form.validity_days,
  quoted_on:        form.quoted_on,
  quotation_prefix: form.quotation_prefix,
  discount_pct:     form.discount_pct,
  transport_fixed:  form.transport_fixed,
  transport_amount: form.transport_fixed ? form.transport_amount : 0,
  advance_pct:      form.advance_pct,
  notes:            form.notes,
  panel_rows: form.panelRows.map(r => ({
    panel_type_id:         r.panel_type_id,
    thickness:             r.thickness,
    density_type:          r.density_type,
    density_kgm3:          r.density_kgm3,
    top_skin_material:     r.top_skin_material,
    top_skin_thickness:    r.top_skin_thickness,
    top_color:             r.top_color,
    top_surface:           r.top_surface,
    bottom_skin_material:  r.bottom_skin_material,
    bottom_skin_thickness: r.bottom_skin_thickness,
    bottom_color:          r.bottom_color,
    guard_film:            r.guard_film,
    cello_tap:             r.cello_tap,
    hsn_code:              r.hsn_code,
    sizes: r.sizes.filter(s => s.length_mm && s.nos).map(s => ({
      length_mm:    s.length_mm,
      nos:          s.nos,
      rate_per_sqm: s.rate_per_sqm,
    })),
  })).filter(r => r.panel_type_id && r.sizes.length > 0),
  accessories: form.accessories.map(a => ({
    type:         a.type,
    accessory_id: a.type === 'standard' ? a.accessory_id : null,
    description:  a.description,
    unit:         a.unit,
    qty:          a.qty,
    rate:         a.rate,
    amount:       a.amount,
    hsn_code:     a.hsn_code,
  })),
})

const validate = () => {
  errorMsg.value = ''
  if (!form.customer_id)   { errorMsg.value = 'Please select a customer.'; return false }
  const validRows = form.panelRows.filter(r => r.panel_type_id && r.sizes.length > 0 && r.sizes.some(s => s.length_mm && s.nos))
  if (!validRows.length)   { errorMsg.value = 'Add at least one panel row with size details.'; return false }
  return true
}

const saveDraft = async () => {
  if (!validate()) return
  saving.value = true; errorMsg.value = ''; successMsg.value = ''
  try {
    const payload = buildPayload()
    if (form.id) {
      const { data } = await api.put(`/quotations/${form.id}`, payload)
      successMsg.value = `✅ BOQ updated — ${data.data?.quotation_no || form.quotation_no}`
    } else {
      const { data } = await api.post('/quotations', payload)
      form.id = data.data?.id
      form.quotation_no = data.data?.quotation_no || ''
      successMsg.value = `✅ BOQ saved as Draft — ${form.quotation_no}`
    }
  } catch (e) {
    const err = e.response?.data
    if (err?.errors) {
      errorMsg.value = Object.values(err.errors).flat().join(', ')
    } else {
      errorMsg.value = err?.message || 'Save failed'
    }
  } finally {
    saving.value = false
  }
}

const sendQuotation = async () => {
  if (!validate()) return
  await saveDraft()
  if (!form.id || errorMsg.value) return
  saving.value = true
  try {
    await api.post(`/quotations/${form.id}/send`)
    successMsg.value = '✅ Quotation sent to customer!'
    setTimeout(() => router.push('/quotations/' + form.id), 1500)
  } catch (e) {
    errorMsg.value = e.response?.data?.message || 'Send failed'
  } finally {
    saving.value = false
  }
}

const previewPdf = () => window.open(`/api/quotations/${form.id}/pdf`, '_blank')

const saveNewCustomer = async () => {
  custError.value = ''; saving.value = true
  try {
    const { data } = await api.post('/customers', { ...newCust, is_active: true })
    customers.value.push(data.data)
    form.customer_id = data.data.id
    showAddCustomer.value = false
    Object.keys(newCust).forEach(k => newCust[k] = '')
    newCust.type = 'retail'
    onCustomerChange()
  } catch (e) { custError.value = e.response?.data?.message || 'Failed' }
  finally { saving.value = false }
}

// ── Load & Edit ─────────────────────────────────────────────────────────

onMounted(async () => {
  const [custRes, ptRes, accRes, meRes] = await Promise.all([
    api.get('/customers', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    api.get('/panel-types').catch(() => ({ data: { data: [] } })),
    api.get('/accessories').catch(() => ({ data: { data: [] } })),
    api.get('/auth/me').catch(() => null),
  ])
  customers.value   = custRes.data?.data?.data ?? custRes.data?.data ?? []
  panelTypes.value  = ptRes.data?.data?.data ?? ptRes.data?.data ?? []
  accessories.value = accRes.data?.data?.data ?? accRes.data?.data ?? []

  // Detect company state from GSTIN
  if (meRes?.data?.data?.company?.gstin) {
    const gstin = meRes.data.data.company.gstin
    const stateNumMap = {'24':'GJ','27':'MH','29':'KA','07':'DL','33':'TN','09':'UP','08':'RJ','32':'KL'}
    const num = gstin.substring(0, 2)
    COMPANY_STATE.value = stateNumMap[num] || 'GJ'
  }

  // Load for edit
  if (isEdit.value) {
    try {
      const { data } = await api.get(`/quotations/${route.params.id}`)
      const q = data.data
      Object.assign(form, {
        id: q.id, quotation_no: q.quotation_no, quotation_prefix: q.quotation_prefix || 'SCP',
        customer_id: q.customer_id, project_name: q.project_name || '',
        project_location: q.project_location || '', quality_grade: q.quality_grade || 'High',
        validity_days: q.validity_days || 10, quoted_on: q.quoted_on || new Date().toISOString().split('T')[0],
        discount_pct: q.discount_pct || 0, transport_fixed: !!q.transport_fixed,
        transport_amount: q.transport_amount || 0, advance_pct: q.advance_pct || 50,
        notes: q.notes || '',
      })

      // Rebuild panel rows
      form.panelRows = []
      for (const item of (q.items || [])) {
        const row = newPanelRow()
        Object.assign(row, {
          panel_type_id: item.panel_type_id, thickness: item.thickness,
          density_type: item.density_type, density_kgm3: item.density_kgm3,
          top_skin_material: item.top_skin_material, top_skin_thickness: item.top_skin_thickness,
          top_color: item.top_color, top_surface: item.top_surface,
          bottom_skin_material: item.bottom_skin_material, bottom_skin_thickness: item.bottom_skin_thickness,
          bottom_color: item.bottom_color, guard_film: item.guard_film, cello_tap: item.cello_tap,
          hsn_code: item.hsn_code, total_sqm: item.total_sqm,
        })
        row.sizes = (item.sizes || []).map(s => ({
          length_mm: s.length_mm, width_mm: 1000, nos: s.nos,
          sqm: s.sqm || (s.length_mm / 1000) * s.nos,
          rate_per_sqm: s.rate_per_sqm, amount: s.amount,
        }))
        form.panelRows.push(row)
      }
      calcTotals()
    } catch (e) { errorMsg.value = 'Failed to load quotation for editing' }
  }
})
</script>

<style scoped>
.boq-create { padding-bottom: 40px; }
.radio-label { display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px }
.panel-row-card { border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:16px;background:#fafbff; }
.panel-row-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid #e3f2fd; }
.row-badge { font-weight:700;font-size:13px;color:var(--primary);background:var(--primary-light);padding:3px 10px;border-radius:10px; }
.sizes-section { background:white;border:1px solid #e0e0e0;border-radius:4px;padding:12px;margin-top:12px; }
.sum-line { display:flex;justify-content:space-between;padding:7px 4px;border-bottom:1px solid #f0f0f0;font-size:14px; }
.sum-line:last-child { border-bottom:none }
.sum-line.subtotal { font-weight:600;border-top:2px solid #ccc;border-bottom:2px solid #ccc;padding:10px 4px }
.sum-line.grand { font-size:18px;font-weight:800;color:var(--primary);border-top:3px solid var(--primary);border-bottom:3px solid var(--primary);padding:12px 4px }
</style>
