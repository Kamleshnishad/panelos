<template>
  <div class="qc-wrap">
    <div class="qc-header">
      <button class="btn btn-ghost" @click="onBack">← Back</button>
      <h2>{{ headerTitle }}</h2>
      <div class="header-actions">
        <template v-if="isBoq">
          <span class="boq-mode-tag" title="Rates are added later by Sales when this BOQ is converted to a quotation">BOQ — no rates</span>
          <button class="btn btn-primary" :disabled="saving || panelRows.length === 0" @click="save('boq')">{{ isEdit ? 'Update BOQ' : 'Save BOQ' }}</button>
        </template>
        <template v-else>
          <button class="btn btn-ghost" :disabled="saving" @click="save('draft')">Save Draft</button>
          <button class="btn btn-primary" :disabled="saving || panelRows.length === 0" @click="save('send')">Save &amp; Send</button>
        </template>
      </div>
    </div>

    <div v-if="submitError" class="error-banner">{{ submitError }}</div>

    <div class="qc-body">
      <!-- ── Section 1: Header Info ─────────────────────────────── -->
      <section class="card">
        <h3>{{ isBoq ? 'BOQ Details' : 'Quotation Details' }}</h3>
        <div class="form-grid">
          <!-- Customer -->
          <div class="form-group">
            <label>Customer *</label>
            <div class="customer-row">
              <select v-model="form.customer_id" required @change="onCustomerChange">
                <option value="">— Select Customer —</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.city }})</option>
              </select>
              <button type="button" class="btn-sm btn-add" @click="showAddCustomer = true">+ New</button>
            </div>
          </div>

          <div class="form-group">
            <label>Project Name</label>
            <input v-model="form.project_name" placeholder="e.g. Cold Storage Unit, Pune" />
          </div>

          <div class="form-group">
            <label>Project Location</label>
            <input v-model="form.project_location" placeholder="City / Site address" />
          </div>

          <div class="form-group">
            <label>Quality Grade</label>
            <select v-model="form.quality_grade">
              <option value="High">High (Premium)</option>
              <option value="Medium">Medium</option>
              <option value="Standard">Standard</option>
            </select>
          </div>

          <div class="form-group">
            <label>Quotation Date</label>
            <input v-model="form.quoted_on" type="date" />
          </div>

          <div class="form-group">
            <label>Validity (days)</label>
            <input v-model.number="form.validity_days" type="number" min="1" max="365" />
          </div>

          <div class="form-group" v-if="!isBoq">
            <label>Discount %</label>
            <input v-model.number="form.discount_pct" type="number" min="0" max="100" step="0.5" @input="recalcTotals" />
          </div>

          <div class="form-group" v-if="!isBoq">
            <label>Advance %</label>
            <input v-model.number="form.advance_pct" type="number" min="0" max="100" step="5" @input="recalcTotals" />
          </div>

          <div class="form-group" v-if="!isBoq">
            <label>Transportation</label>
            <div class="transport-row">
              <label class="toggle-label">
                <input type="checkbox" v-model="form.transport_fixed" @change="recalcTotals" />
                Fixed Amount
              </label>
              <input v-if="form.transport_fixed" v-model.number="form.transport_amount" type="number" min="0" placeholder="₹ amount" @input="recalcTotals" />
              <span v-else class="text-muted">Extra as Actual</span>
            </div>
          </div>

          <div class="form-group full-span">
            <label>Notes / Special Instructions</label>
            <textarea v-model="form.notes" rows="2" placeholder="Any special conditions, exclusions, etc."></textarea>
          </div>
        </div>

        <!-- GST indicator -->
        <div v-if="selectedCustomer" class="gst-indicator" :class="{ inter: isInterState }">
          GST Type: <strong>{{ isInterState ? 'IGST (Inter-state)' : 'CGST + SGST (Intra-state)' }}</strong>
          &nbsp;|&nbsp; Customer: {{ selectedCustomer.name }}
          <span v-if="selectedCustomer.gstin"> &nbsp;| GSTIN: {{ selectedCustomer.gstin }}</span>
        </div>
      </section>

      <!-- ── Section 2: Panel Rows ──────────────────────────────── -->
      <section class="card">
        <div class="section-header">
          <h3>Panel Specification Rows</h3>
          <button class="btn btn-secondary" @click="addPanelRow">+ Add Panel Row</button>
        </div>

        <div v-if="panelRows.length === 0" class="empty-hint">
          Click "Add Panel Row" to start adding PUF/PIR panel specifications.
        </div>

        <div v-for="(row, ri) in panelRows" :key="row._key" class="panel-row-card">
          <div class="panel-row-header" @click="row._collapsed = !row._collapsed">
            <button class="row-toggle" :aria-label="row._collapsed ? 'Expand row' : 'Collapse row'" :aria-expanded="!row._collapsed" @click.stop="row._collapsed = !row._collapsed">{{ row._collapsed ? '▸' : '▾' }}</button>
            <span class="row-num">Row {{ ri + 1 }}</span>
            <span class="row-summary">{{ rowSummary(row) || 'New panel row — click to configure' }}</span>
            <div class="row-header-actions" @click.stop>
              <button class="btn-sm btn-dup" @click="duplicatePanelRow(ri)" title="Duplicate Row">Dup</button>
              <button class="btn-sm btn-del" @click="removePanelRow(ri)" title="Remove Row">✕</button>
            </div>
          </div>

          <div v-show="!row._collapsed" class="panel-row-body">
          <!-- Panel type & core spec -->
          <div class="form-grid compact">
            <div class="form-group">
              <label>Panel Type *</label>
              <select v-model="row.panel_type_id" @change="onPanelTypeChange(row)">
                <option value="">— Select Type —</option>
                <option v-for="pt in panelTypes" :key="pt.id" :value="pt.id">{{ pt.name }}</option>
              </select>
            </div>

            <div class="form-group">
              <label>Application</label>
              <select v-model="row.application">
                <option value="">— Select —</option>
                <option v-for="a in applicationOptions" :key="a" :value="a">{{ a }}</option>
              </select>
            </div>

            <div class="form-group">
              <label>Thickness (mm) *</label>
              <select v-model.number="row.thickness" @change="fetchSuggestedRate(row)">
                <option v-for="t in thicknessOptions" :key="t" :value="t">{{ t }} mm</option>
              </select>
            </div>

            <div class="form-group">
              <label>Core / Insulation</label>
              <select v-model="row.density_type" @change="fetchSuggestedRate(row)">
                <option v-for="c in coreTypeOptions" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>

            <div class="form-group">
              <label>Density (kg/m³)</label>
              <input v-model.number="row.density_kgm3" type="number" step="1" min="10" max="200" />
            </div>

            <div class="form-group">
              <label>Fixing System</label>
              <select v-model="row.fixing_system">
                <option value="">— Select —</option>
                <option v-for="f in fixingSystemOptions" :key="f" :value="f">{{ f }}</option>
              </select>
            </div>
          </div>

          <!-- Skin specification -->
          <div class="skin-spec">
            <div class="skin-col">
              <div class="skin-label top-label">TOP SKIN</div>
              <div class="form-grid compact">
                <div class="form-group">
                  <label>Material</label>
                  <select v-model="row.top_skin_material">
                    <option v-for="m in skinMaterialOptions" :key="m.v" :value="m.v">{{ m.label }}</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>Thickness (mm)</label>
                  <select v-model.number="row.top_skin_thickness" @change="fetchSuggestedRate(row)">
                    <option v-for="t in skinThicknessOptions" :key="t" :value="t">{{ t }}</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>Color</label>
                  <input v-model="row.top_color" list="color-list" placeholder="Off White" />
                </div>
                <div class="form-group">
                  <label>RAL Code</label>
                  <input v-model="row.top_color_ral" list="ral-list" placeholder="e.g. 9002" />
                </div>
                <div class="form-group">
                  <label>Surface</label>
                  <select v-model="row.top_surface" @change="fetchSuggestedRate(row)">
                    <option value="PLAIN">Plain</option>
                    <option value="RIBBED">Ribbed</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="skin-col">
              <div class="skin-label-row">
                <div class="skin-label btm-label">BOTTOM SKIN</div>
                <label class="same-skin-toggle"><input type="checkbox" v-model="row.same_skin" /> Same as top</label>
              </div>
              <div v-if="row.same_skin" class="same-skin-note">Mirrors the top skin (material, thickness, colour).</div>
              <div v-else class="form-grid compact">
                <div class="form-group">
                  <label>Material</label>
                  <select v-model="row.bottom_skin_material">
                    <option v-for="m in skinMaterialOptions" :key="m.v" :value="m.v">{{ m.label }}</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>Thickness (mm)</label>
                  <select v-model.number="row.bottom_skin_thickness" @change="fetchSuggestedRate(row)">
                    <option v-for="t in skinThicknessOptions" :key="t" :value="t">{{ t }}</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>Color</label>
                  <input v-model="row.bottom_color" list="color-list" placeholder="Off White" />
                </div>
                <div class="form-group">
                  <label>RAL Code</label>
                  <input v-model="row.bottom_color_ral" list="ral-list" placeholder="e.g. 9002" />
                </div>
                <div class="form-group">
                  <label>Surface</label>
                  <select v-model="row.bottom_surface">
                    <option value="PLAIN">Plain</option>
                    <option value="RIBBED">Ribbed</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Guard film / cello tap -->
          <div class="extras-row">
            <label class="toggle-label"><input type="checkbox" v-model="row.guard_film" /> Guard Film</label>
            <label class="toggle-label"><input type="checkbox" v-model="row.cello_tap" /> Cello Tap</label>
            <div class="form-group inline-group">
              <label>HSN Code</label>
              <input v-model="row.hsn_code" style="width:120px" />
            </div>
          </div>

          <!-- Size sub-table -->
          <div class="size-section">
            <div class="size-section-header">
              <span>SIZE BREAKDOWN</span>
              <button class="btn-sm btn-add-size" @click="addSizeRow(row)">+ Size</button>
            </div>
            <p class="dl-legend">Width is fixed at <strong>1000&nbsp;mm</strong> (panel module). Lengths under <strong>2000&nbsp;mm</strong> are produced at <strong>doubled length &amp; cut</strong> (shown as <span class="warn-tag">⚠ DL</span>).</p>
            <table class="size-table">
              <thead>
                <tr>
                  <th>Length (mm)</th>
                  <th>Width (mm)</th>
                  <th>NOS</th>
                  <th>SQM</th>
                  <th v-if="!isBoq">Rate (₹/SQM)</th>
                  <th v-if="!isBoq">Amount (₹)</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(sz, si) in row.sizes" :key="sz._key">
                  <td>
                    <input v-model.number="sz.length_mm" type="number" min="500" max="14000" class="sz-input" @input="onSizeChange(row, sz)" />
                    <span v-if="sz.length_mm > 0 && sz.length_mm < 2000" class="warn-tag" title="Will be produced at doubled length and cut">⚠ DL</span>
                  </td>
                  <td class="width-fixed">1000 <span class="wf-unit">mm</span></td>
                  <td><input v-model.number="sz.nos" type="number" min="1" class="sz-input nos-input" @input="onSizeChange(row, sz)" /></td>
                  <td class="bold-cell">{{ fmtSqm(sz.sqm) }}</td>
                  <td v-if="!isBoq">
                    <input v-model.number="sz.rate_per_sqm" type="number" min="0" step="5" class="sz-input rate-input" @input="onSizeChange(row, sz)" />
                    <span v-if="row._suggestedRate" class="suggested-tag" @click="applyRate(row, sz)" title="Apply suggested rate">≈ {{ row._suggestedRate }}</span>
                  </td>
                  <td v-if="!isBoq" class="bold-cell amount-cell">₹ {{ fmtNum(sz.amount) }}</td>
                  <td><button class="btn-sz-del" @click="removeSizeRow(row, si)">✕</button></td>
                </tr>
                <tr v-if="row.sizes.length === 0">
                  <td :colspan="isBoq ? 5 : 7" class="empty-hint-sm">Add at least one size row</td>
                </tr>
                <!-- Row totals -->
                <tr class="size-total-row" v-if="row.sizes.length > 0">
                  <td colspan="2" class="text-right bold">Total</td>
                  <td class="bold">{{ rowTotalNos(row) }}</td>
                  <td class="bold">{{ fmtSqm(rowTotalSqm(row)) }}</td>
                  <td v-if="!isBoq" class="text-muted">avg ₹ {{ rowAvgRate(row) }}</td>
                  <td v-if="!isBoq" class="bold amount-cell">₹ {{ fmtNum(rowTotalAmount(row)) }}</td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </div>
          </div>
        </div>
      </section>

      <!-- ── Section 3: Accessories ─────────────────────────────── -->
      <section class="card">
        <div class="section-header">
          <h3>Accessories / Installation</h3>
          <div class="acc-add-btns">
            <button class="btn btn-secondary" @click="addAccessoryRow('standard')">+ Accessory</button>
            <button class="btn btn-secondary" @click="addAccessoryRow('door')">+ Door/Window</button>
            <button v-if="!isBoq" class="btn btn-secondary" @click="addAccessoryRow('installation')">+ Installation</button>
          </div>
        </div>

        <div v-if="accessories_rows.length === 0" class="empty-hint">No accessories or installation charges added.</div>

        <div v-for="(acc, ai) in accessories_rows" :key="acc._key" class="acc-row-card" :class="{ 'acc-door': acc.type === 'door' }">
          <!-- Row header with type badge and delete -->
          <div class="acc-row-header">
            <span class="acc-type-badge" :class="acc.type">
              {{ acc.type === 'door' ? 'Door/Window' : acc.type === 'installation' ? 'Installation' : 'Accessory' }}
            </span>
            <button class="btn-sz-del acc-del-btn" @click="accessories_rows.splice(ai, 1); recalcTotals()">✕ Remove</button>
          </div>

          <!-- Standard / Installation row -->
          <div v-if="acc.type !== 'door'" class="form-grid compact acc-fields">
            <div class="form-group">
              <label>Item</label>
              <select v-if="acc.type === 'standard'" v-model="acc.accessory_id" @change="onAccChange(acc)">
                <option value="">— Custom —</option>
                <option v-for="a in masterAccessories" :key="a.id" :value="a.id">{{ a.name }}</option>
              </select>
              <input v-else value="Installation Charges" readonly class="readonly-field" />
            </div>
            <div class="form-group">
              <label>Description</label>
              <input v-model="acc.description" placeholder="Description" />
            </div>
            <div class="form-group">
              <label>Qty</label>
              <input v-model.number="acc.qty" type="number" min="0" step="0.1" @input="calcAccAmount(acc)" />
            </div>
            <div class="form-group">
              <label>Unit</label>
              <input v-model="acc.unit" placeholder="NOS" />
            </div>
            <div class="form-group" v-if="!isBoq">
              <label>Rate (₹)</label>
              <input v-model.number="acc.rate" type="number" min="0" @input="calcAccAmount(acc)" />
            </div>
            <div class="form-group" v-if="!isBoq">
              <label>Amount (₹)</label>
              <input :value="fmtNum(acc.amount)" readonly class="readonly-field bold" />
            </div>
          </div>

          <!-- Door/Window special row -->
          <div v-else class="door-spec">
            <div class="form-grid compact acc-fields">
              <div class="form-group">
                <label>Door/Window Type</label>
                <select v-model="acc.door_type" @change="calcAccAmount(acc)">
                  <option value="sliding_door">Sliding Door</option>
                  <option value="hinged_door">Hinged Door</option>
                  <option value="window">Window / Vision Panel</option>
                  <option value="pass_through">Pass-through Opening</option>
                </select>
              </div>
              <div class="form-group">
                <label>Width (mm)</label>
                <input v-model.number="acc.door_width" type="number" min="100" max="5000" placeholder="e.g. 900" @input="calcAccAmount(acc)" />
              </div>
              <div class="form-group">
                <label>Height (mm)</label>
                <input v-model.number="acc.door_height" type="number" min="100" max="5000" placeholder="e.g. 2100" @input="calcAccAmount(acc)" />
              </div>
              <div class="form-group">
                <label>NOS</label>
                <input v-model.number="acc.qty" type="number" min="1" @input="calcAccAmount(acc)" />
              </div>
              <div class="form-group" v-if="!isBoq">
                <label>Rate (₹/NOS)</label>
                <input v-model.number="acc.rate" type="number" min="0" @input="calcAccAmount(acc)" />
              </div>
              <div class="form-group" v-if="!isBoq">
                <label>Amount (₹)</label>
                <input :value="fmtNum(acc.amount)" readonly class="readonly-field bold" />
              </div>
            </div>
            <div class="door-dimensions">
              <span class="dim-badge">{{ acc.door_width || 0 }} × {{ acc.door_height || 0 }} mm</span>
              <span class="dim-badge">Area: {{ fmtSqm((acc.door_width || 0) * (acc.door_height || 0) / 1e6) }} SQM each</span>
              <input v-model="acc.description" class="door-desc-input" placeholder="Notes / special requirements (optional)" />
            </div>
          </div>
        </div>
      </section>

      <!-- ── Section 4: Totals Summary ──────────────────────────── -->
      <!-- BOQ mode: spec-only summary, no money -->
      <section v-if="isBoq" class="card totals-card boq-summary">
        <h3>BOQ Summary</h3>
        <div class="totals-grid">
          <div class="t-row"><span>Panel Rows</span><span>{{ panelRows.length }}</span></div>
          <div class="t-row"><span>Total Panels (NOS)</span><span>{{ boqTotalNos }}</span></div>
          <div class="t-row" v-if="accessories_rows.length"><span>Accessories / Doors</span><span>{{ accessories_rows.length }}</span></div>
          <div class="t-row grand boq-grand"><span>TOTAL SQM</span><span>{{ totals.total_sqm.toFixed(3) }}</span></div>
          <p class="boq-note">Rates are added by Sales after this BOQ is converted to a quotation.</p>
        </div>
      </section>

      <section v-else class="card totals-card">
        <h3>Summary</h3>
        <div class="totals-grid">
          <div class="t-row">
            <span>Panel Subtotal</span>
            <span>₹ {{ fmtNum(totals.panel_subtotal) }}</span>
          </div>
          <div class="t-row" v-if="totals.accessory_subtotal > 0">
            <span>Accessories / Installation</span>
            <span>₹ {{ fmtNum(totals.accessory_subtotal) }}</span>
          </div>
          <div class="t-row border-top">
            <span>Subtotal</span>
            <span>₹ {{ fmtNum(totals.subtotal) }}</span>
          </div>
          <div class="t-row discount" v-if="form.discount_pct > 0">
            <span>Discount ({{ form.discount_pct }}%)</span>
            <span>- ₹ {{ fmtNum(totals.discount_amount) }}</span>
          </div>
          <div class="t-row border-top">
            <span>Taxable Amount</span>
            <span>₹ {{ fmtNum(totals.taxable_amount) }}</span>
          </div>
          <div class="t-row" v-if="isInterState">
            <span>IGST @ 18%</span>
            <span>₹ {{ fmtNum(totals.igst) }}</span>
          </div>
          <template v-else>
            <div class="t-row"><span>CGST @ 9%</span><span>₹ {{ fmtNum(totals.cgst) }}</span></div>
            <div class="t-row"><span>SGST @ 9%</span><span>₹ {{ fmtNum(totals.sgst) }}</span></div>
          </template>
          <div class="t-row" v-if="form.transport_fixed && form.transport_amount > 0">
            <span>Transportation</span>
            <span>₹ {{ fmtNum(form.transport_amount) }}</span>
          </div>
          <div class="t-row" v-else>
            <span>Transportation</span>
            <span class="text-muted">Extra as Actual</span>
          </div>
          <div class="t-row" v-if="totals.round_off !== 0">
            <span>Round Off</span>
            <span>₹ {{ fmtNum(totals.round_off) }}</span>
          </div>
          <div class="t-row grand">
            <span>GRAND TOTAL</span>
            <span>₹ {{ fmtNum(totals.grand_total) }}</span>
          </div>
          <div class="t-row">
            <span>Advance ({{ form.advance_pct }}%)</span>
            <span>₹ {{ fmtNum(totals.advance) }}</span>
          </div>
          <div class="t-row balance">
            <span>Balance Due</span>
            <span>₹ {{ fmtNum(totals.balance) }}</span>
          </div>
          <div class="t-row">
            <span>Total SQM</span>
            <span>{{ totals.total_sqm.toFixed(3) }} SQM</span>
          </div>
        </div>
      </section>
    </div>

    <!-- Sticky running totals (stays visible while scrolling rows) -->
    <div v-if="panelRows.length" class="sticky-totals">
      <template v-if="isBoq">
        <span class="st-item"><label>Panels</label>{{ boqTotalNos }}</span>
        <span class="st-item st-grand"><label>Total SQM</label>{{ totals.total_sqm.toFixed(2) }}</span>
      </template>
      <template v-else>
        <span class="st-item"><label>Subtotal</label>₹ {{ fmtNum(totals.subtotal) }}</span>
        <span class="st-item"><label>GST</label>₹ {{ fmtNum(totals.cgst + totals.sgst + totals.igst) }}</span>
        <span class="st-item st-grand"><label>Grand Total</label>₹ {{ fmtNum(totals.grand_total) }}</span>
        <span class="st-item"><label>Balance</label>₹ {{ fmtNum(totals.balance) }}</span>
      </template>
      <div class="st-actions">
        <button v-if="isBoq" class="btn btn--primary btn--sm" :disabled="saving || !panelRows.length" @click="save('boq')">{{ isEdit ? 'Update BOQ' : 'Save BOQ' }}</button>
        <template v-else>
          <button class="btn btn--ghost btn--sm" :disabled="saving" @click="save('draft')">Save Draft</button>
          <button class="btn btn--primary btn--sm" :disabled="saving || !panelRows.length" @click="save('send')">Save &amp; Send</button>
        </template>
      </div>
    </div>

    <!-- New customer modal -->
    <div v-if="showAddCustomer" class="cust-overlay" @click.self="showAddCustomer = false">
      <div class="cust-modal" role="dialog" aria-modal="true" aria-label="Add customer">
        <h3>New Customer</h3>
        <div class="cust-grid">
          <div class="form-group"><label>Name *</label><input v-model="newCust.name" ref="newCustNameInput" /></div>
          <div class="form-group"><label>Type</label>
            <select v-model="newCust.type">
              <option value="retail">Retail</option>
              <option value="wholesale">Wholesale</option>
              <option value="distributor">Distributor</option>
              <option value="corporate">Corporate</option>
            </select>
          </div>
          <div class="form-group"><label>City</label><input v-model="newCust.city" /></div>
          <div class="form-group"><label>State Code</label><input v-model="newCust.state_code" maxlength="2" placeholder="GJ" /></div>
          <div class="form-group"><label>GSTIN</label><input v-model="newCust.gstin" placeholder="optional" /></div>
          <div class="form-group"><label>Phone</label><input v-model="newCust.phone" /></div>
        </div>
        <div v-if="custError" class="error-banner">{{ custError }}</div>
        <div class="cust-actions">
          <button class="btn btn-ghost" @click="showAddCustomer = false">Cancel</button>
          <button class="btn btn-primary" :disabled="custSaving || !newCust.name" @click="saveNewCustomer">{{ custSaving ? 'Saving…' : 'Add Customer' }}</button>
        </div>
      </div>
    </div>

    <!-- Color datalist -->
    <datalist id="color-list">
      <option>Off White</option>
      <option>White</option>
      <option>Ivory</option>
      <option>Light Grey</option>
      <option>Dark Blue</option>
      <option>Bare Metal</option>
    </datalist>

    <!-- RAL code datalist (common PPGI/PPGL shades) -->
    <datalist id="ral-list">
      <option value="9002">9002 — Grey White / Off White</option>
      <option value="9001">9001 — Cream</option>
      <option value="9010">9010 — Pure White</option>
      <option value="5012">5012 — Light Blue</option>
      <option value="5010">5010 — Gentian Blue</option>
      <option value="7035">7035 — Light Grey</option>
      <option value="6011">6011 — Reseda Green</option>
      <option value="3000">3000 — Flame Red</option>
    </datalist>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import quotationService from '../services/quotationService.js'
import { confirmDialog, toastSuccess, toastError } from '../services/ui.js'

const props = defineProps({
  editId: { type: Number, default: null },
  mode:   { type: String, default: 'quotation' }, // 'quotation' | 'boq'
  prefillCustomerId: { type: Number, default: null }, // from a converted Lead
  leadId: { type: Number, default: null },            // back-link target
})
const emit = defineEmits(['cancel', 'saved'])

const isEdit = computed(() => !!props.editId)
const isBoq  = computed(() => props.mode === 'boq')
const headerTitle = computed(() =>
  isBoq.value ? (isEdit.value ? 'Edit BOQ' : 'New BOQ')
              : (isEdit.value ? 'Edit Quotation' : 'New Quotation'))
const boqTotalNos = computed(() =>
  panelRows.value.reduce((s, row) => s + rowTotalNos(row), 0))

// ── Reference data ────────────────────────────────────────────────
const customers = ref([])
const panelTypes = ref([])
const masterAccessories = ref([])
const showAddCustomer = ref(false)
const newCust = reactive({ name: '', type: 'retail', city: '', state_code: '', gstin: '', phone: '' })
const newCustNameInput = ref(null)
const custSaving = ref(false)
const custError = ref(null)
const submitError = ref(null)
const saving = ref(false)

watch(showAddCustomer, (v) => { if (v) nextTick(() => newCustNameInput.value?.focus()) })

async function saveNewCustomer() {
  if (!newCust.name) return
  custSaving.value = true; custError.value = null
  try {
    const res = await quotationService.createCustomer({ ...newCust })
    const c = res?.data ?? res
    customers.value.push(c)
    form.customer_id = c.id
    onCustomerChange()
    showAddCustomer.value = false
    Object.assign(newCust, { name: '', type: 'retail', city: '', state_code: '', gstin: '', phone: '' })
    toastSuccess('Customer added.')
  } catch (e) {
    custError.value = e?.response?.data?.message ?? 'Failed to add customer.'
  } finally { custSaving.value = false }
}
const companyStateCode = ref(null)   // loaded from /auth/me at mount

const thicknessOptions = [10, 25, 30, 40, 50, 60, 75, 80, 100, 120, 150, 200]
const skinThicknessOptions = [0.30, 0.35, 0.40, 0.45, 0.50, 0.60]
// Full product range (matches the customer's catalogue + backend validation enums)
const applicationOptions = ['Wall', 'Roof', 'Cold Room', 'Partition', 'Clean Room', 'Ceiling', 'PEB Shade', 'Architectural']
const coreTypeOptions = ['PUF', 'PIR', 'Rockwool', 'EPS', 'Glasswool']
const fixingSystemOptions = ['Cam-Lock', 'Secret-Fix', 'Standing-Seam', 'Lap-Joint', 'Visible-Fix']
const skinMaterialOptions = [
  { v: 'PPGI', label: 'PPGI' },
  { v: 'PPGL', label: 'PPGL (Galvalume)' },
  { v: 'GI', label: 'GI (Plain)' },
  { v: 'SS304', label: 'Stainless Steel 304' },
  { v: 'Aluminium', label: 'Aluminium' },
]

// ── Header form ───────────────────────────────────────────────────
const form = reactive({
  customer_id: '',
  project_name: '',
  project_location: '',
  quality_grade: 'High',
  validity_days: 10,
  quoted_on: new Date().toISOString().slice(0, 10),
  discount_pct: 0,
  advance_pct: 50,
  transport_fixed: false,
  transport_amount: 0,
  notes: '',
})

// ── Panel rows ────────────────────────────────────────────────────
const panelRows = ref([])
let _keySeq = 1

function makePanelRow() {
  return {
    _key: _keySeq++,
    _suggestedRate: null,
    _collapsed: false,
    same_skin: true,
    panel_type_id: '',
    application: '',
    thickness: 50,
    density_type: 'PUF',
    density_kgm3: 40,
    top_skin_material: 'PPGI',
    top_skin_thickness: 0.40,
    top_color: 'Off White',
    top_color_ral: '',
    top_surface: 'PLAIN',
    bottom_skin_material: 'PPGI',
    bottom_skin_thickness: 0.40,
    bottom_color: 'Off White',
    bottom_color_ral: '',
    bottom_surface: 'PLAIN',
    guard_film: false,
    cello_tap: false,
    fixing_system: '',
    hsn_code: '39259010',
    sizes: [],
  }
}

function makeSizeRow() {
  return { _key: _keySeq++, length_mm: '', width_mm: 1000, nos: 1, sqm: 0, rate_per_sqm: 0, amount: 0 }
}

function addPanelRow() {
  const row = makePanelRow()
  row.sizes.push(makeSizeRow())
  panelRows.value.push(row)
}

function duplicatePanelRow(ri) {
  const src = panelRows.value[ri]
  const copy = JSON.parse(JSON.stringify(src))
  copy._key = _keySeq++
  copy.sizes = copy.sizes.map(s => ({ ...s, _key: _keySeq++ }))
  panelRows.value.splice(ri + 1, 0, copy)
}

function removePanelRow(ri) {
  panelRows.value.splice(ri, 1)
  recalcTotals()
}

function addSizeRow(row) {
  row.sizes.push(makeSizeRow())
}

function removeSizeRow(row, si) {
  row.sizes.splice(si, 1)
  recalcTotals()
}

function onPanelTypeChange(row) {
  const pt = panelTypes.value.find(p => p.id === row.panel_type_id)
  if (!pt) return
  const name = (pt.name || '').toLowerCase()
  // Smart defaults from the SKU name — user can override.
  if (!row.application) {
    if (name.includes('cold')) row.application = 'Cold Room'
    else if (name.includes('clean')) row.application = 'Clean Room'
    else if (name.includes('partition')) row.application = 'Partition'
    else if (name.includes('ceiling')) row.application = 'Ceiling'
    else if (name.includes('peb') || name.includes('shade')) row.application = 'PEB Shade'
    else if (name.includes('roof')) row.application = 'Roof'
    else if (name.includes('wall')) row.application = 'Wall'
  }
  if (name.includes('pir')) row.density_type = 'PIR'
  else if (name.includes('rockwool') || name.includes('rock wool')) row.density_type = 'Rockwool'
  // Roof panels are typically ribbed; walls plain (still user-overridable).
  row.top_surface = (name.includes('roof') || name.includes('ribbed') || name.includes('tuff')) ? 'RIBBED' : 'PLAIN'
  fetchSuggestedRate(row)
}

async function fetchSuggestedRate(row) {
  if (!row.panel_type_id || !form.customer_id) return
  try {
    const res = await quotationService.getSuggestedRate({
      panel_type_id: row.panel_type_id,
      customer_id: form.customer_id,
      quality_grade: form.quality_grade,
      thickness: row.thickness,
      density_type: row.density_type,
      top_skin_thickness: row.top_skin_thickness,
      top_surface: row.top_surface,
    })
    // BOQ-first: surface the suggestion as a clickable chip but DO NOT auto-fill.
    // Technical can leave rates blank (BOQ); sales clicks the chip or types the rate.
    row._suggestedRate = res?.data?.rate ?? null
  } catch { /* silent */ }
}

function applyRate(row, sz) {
  if (row._suggestedRate) {
    sz.rate_per_sqm = row._suggestedRate
    onSizeChange(row, sz)
  }
}

function onSizeChange(row, sz) {
  // Panel module width is fixed at 1000mm; read width_mm so the formula stays
  // correct if a non-1m width is ever introduced (was a hard-coded 1000/1000).
  const widthM = (sz.width_mm || 1000) / 1000
  sz.sqm = sz.length_mm > 0 ? (sz.length_mm / 1000) * widthM * (sz.nos || 0) : 0
  sz.amount = sz.sqm * (sz.rate_per_sqm || 0)
  recalcTotals()
}

// ── Accessories ───────────────────────────────────────────────────
const accessories_rows = ref([])

function addAccessoryRow(type = 'standard') {
  if (type === 'installation') {
    accessories_rows.value.push({
      _key: _keySeq++, type: 'installation', accessory_id: 'installation',
      description: 'Erection & Installation Charges', qty: 1, unit: 'SQM', rate: 0, amount: 0,
    })
  } else if (type === 'door') {
    accessories_rows.value.push({
      _key: _keySeq++, type: 'door', accessory_id: 'door',
      door_type: 'hinged_door', door_width: 900, door_height: 2100,
      description: '', qty: 1, unit: 'NOS', rate: 0, amount: 0,
    })
  } else {
    accessories_rows.value.push({
      _key: _keySeq++, type: 'standard', accessory_id: '', description: '', qty: 1, unit: 'NOS', rate: 0, amount: 0,
    })
  }
}

function onAccChange(acc) {
  if (acc.accessory_id) {
    const a = masterAccessories.value.find(x => x.id == acc.accessory_id)
    if (a) {
      acc.description = a.description || a.name
      acc.unit = a.unit || 'NOS'
      acc.rate = a.rate || 0
      calcAccAmount(acc)
    }
  }
}

function calcAccAmount(acc) {
  acc.amount = (acc.qty || 0) * (acc.rate || 0)
  recalcTotals()
}

// ── Customer / inter-state ────────────────────────────────────────
const selectedCustomer = computed(() => customers.value.find(c => c.id == form.customer_id) || null)

const isInterState = computed(() => {
  if (!selectedCustomer.value || !companyStateCode.value) return false
  // Compare customer state_code (e.g. 'GJ') with company state_code from /auth/me
  const custState = (selectedCustomer.value.state_code || '').toUpperCase()
  const coState   = companyStateCode.value.toUpperCase()
  if (!custState || !coState) return false
  return custState !== coState
})

function onCustomerChange() {
  panelRows.value.forEach(row => fetchSuggestedRate(row))
}

// ── Totals ────────────────────────────────────────────────────────
const totals = reactive({
  panel_subtotal: 0,
  accessory_subtotal: 0,
  subtotal: 0,
  discount_amount: 0,
  taxable_amount: 0,
  cgst: 0,
  sgst: 0,
  igst: 0,
  transport: 0,
  round_off: 0,
  grand_total: 0,
  advance: 0,
  balance: 0,
  total_sqm: 0,
})

function recalcTotals() {
  const panelSub = panelRows.value.reduce((s, row) => s + rowTotalAmount(row), 0)
  const accSub = accessories_rows.value.reduce((s, a) => s + (a.amount || 0), 0)
  const subtotal = panelSub + accSub
  const discAmt = subtotal * ((form.discount_pct || 0) / 100)
  const taxable = subtotal - discAmt
  const gstTotal = taxable * 0.18
  const inter = isInterState.value
  const transport = form.transport_fixed ? (form.transport_amount || 0) : 0
  const rawTotal = taxable + gstTotal + transport
  const roundOff = Math.round(rawTotal) - rawTotal
  const grand = rawTotal + roundOff
  const advance = grand * ((form.advance_pct || 0) / 100)
  const sqm = panelRows.value.reduce((s, row) => s + rowTotalSqm(row), 0)

  totals.panel_subtotal = panelSub
  totals.accessory_subtotal = accSub
  totals.subtotal = subtotal
  totals.discount_amount = discAmt
  totals.taxable_amount = taxable
  totals.cgst = inter ? 0 : gstTotal / 2
  totals.sgst = inter ? 0 : gstTotal / 2
  totals.igst = inter ? gstTotal : 0
  totals.transport = transport
  totals.round_off = roundOff
  totals.grand_total = grand
  totals.advance = advance
  totals.balance = grand - advance
  totals.total_sqm = sqm
}

// ── Row helpers ───────────────────────────────────────────────────
function rowTotalSqm(row) { return row.sizes.reduce((s, sz) => s + (sz.sqm || 0), 0) }
function rowTotalNos(row) { return row.sizes.reduce((s, sz) => s + (sz.nos || 0), 0) }
function rowTotalAmount(row) { return row.sizes.reduce((s, sz) => s + (sz.amount || 0), 0) }
function rowAvgRate(row) {
  const sqm = rowTotalSqm(row)
  if (!sqm) return '0.00'
  return fmtNum(rowTotalAmount(row) / sqm)
}
function rowSummary(row) {
  const pt = panelTypes.value.find(p => p.id === row.panel_type_id)
  if (!pt) return ''
  const app = row.application ? `${row.application} | ` : ''
  return `${pt.name} | ${app}${row.thickness}mm ${row.density_type} | ${row.top_surface} | ₹ ${fmtNum(rowTotalAmount(row))}`
}

// ── Format helpers ────────────────────────────────────────────────
function fmtNum(n) {
  return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtSqm(n) { return Number(n || 0).toFixed(3) }

// ── Save ──────────────────────────────────────────────────────────
function failValidation(msg, row = null) {
  submitError.value = msg
  toastError(msg)
  if (row) row._collapsed = false   // expand the offending row so the field is visible
  nextTick(() => document.querySelector('.qc-wrap .error-banner')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
}

async function save(mode) {
  submitError.value = null
  if (!form.customer_id) return failValidation('Please select a customer.')
  if (panelRows.value.length === 0) return failValidation('Add at least one panel row.')
  for (const row of panelRows.value) {
    if (!row.panel_type_id) return failValidation(`Row ${panelRows.value.indexOf(row) + 1}: select a panel type.`, row)
    if (row.sizes.length === 0) return failValidation(`Row ${panelRows.value.indexOf(row) + 1}: add at least one size entry.`, row)
    for (const sz of row.sizes) {
      if (!sz.length_mm || sz.length_mm < 500) return failValidation(`Row ${panelRows.value.indexOf(row) + 1}: length must be at least 500mm.`, row)
    }
  }

  saving.value = true
  try {
    const payload = {
      ...form,
      as_boq: isBoq.value,
      lead_id: props.leadId || null,
      panel_rows: panelRows.value.map(row => ({
        panel_type_id: row.panel_type_id,
        application: row.application || null,
        thickness: row.thickness,
        density_type: row.density_type,
        density_kgm3: row.density_kgm3,
        top_skin_material: row.top_skin_material,
        top_skin_thickness: row.top_skin_thickness,
        top_color: row.top_color,
        top_color_ral: row.top_color_ral || null,
        top_surface: row.top_surface,
        bottom_skin_material: row.same_skin ? row.top_skin_material : row.bottom_skin_material,
        bottom_skin_thickness: row.same_skin ? row.top_skin_thickness : row.bottom_skin_thickness,
        bottom_color: row.same_skin ? row.top_color : row.bottom_color,
        bottom_color_ral: (row.same_skin ? row.top_color_ral : row.bottom_color_ral) || null,
        bottom_surface: row.same_skin ? row.top_surface : (row.bottom_surface || 'PLAIN'),
        guard_film: row.guard_film,
        cello_tap: row.cello_tap,
        fixing_system: row.fixing_system || null,
        hsn_code: row.hsn_code,
        sizes: row.sizes.map(sz => ({
          length_mm: sz.length_mm,
          nos: sz.nos,
          rate_per_sqm: sz.rate_per_sqm || 0,
        })),
      })),
      accessories: accessories_rows.value.map(acc => ({
        type:         acc.type || 'standard',
        accessory_id: acc.accessory_id || null,
        qty:          acc.qty,
        rate:         acc.rate,
        description:  acc.description || null,
        unit:         acc.unit || 'NOS',
        door_type:    acc.door_type   || null,
        door_width:   acc.door_width  || null,
        door_height:  acc.door_height || null,
      })),
    }

    let res
    if (isEdit.value) {
      res = await quotationService.update(props.editId, payload)
    } else {
      res = await quotationService.create(payload)
    }

    const savedId = res?.data?.id ?? res?.id
    if (mode === 'send' && savedId) {
      await quotationService.send(savedId)
    }

    dirty.value = false
    toastSuccess(isBoq.value ? 'BOQ saved.' : (mode === 'send' ? 'Quotation saved & sent.' : 'Quotation saved.'))
    emit('saved', savedId)
  } catch (e) {
    const errs = e?.response?.data?.errors
    if (errs) {
      submitError.value = Object.values(errs).flat().join(' | ')
    } else {
      submitError.value = e?.response?.data?.message ?? 'Failed to save quotation.'
    }
    toastError(submitError.value)
  } finally {
    saving.value = false
  }
}

// ── Load for edit ─────────────────────────────────────────────────
async function loadForEdit() {
  try {
  const res = await quotationService.get(props.editId)
  const q = res?.data ?? res
  form.customer_id      = q.customer_id
  form.project_name     = q.project_name || ''
  form.project_location = q.project_location || ''
  form.quality_grade    = q.quality_grade || 'High'
  form.validity_days    = q.validity_days || 10
  form.quoted_on        = q.quoted_on?.slice(0, 10) || form.quoted_on
  form.discount_pct     = q.discount_pct || 0
  form.advance_pct      = q.advance_pct || 50
  form.transport_fixed  = !!q.transport_fixed
  form.transport_amount = q.transport_amount || 0
  form.notes            = q.notes || ''

  panelRows.value = (q.items || []).map(item => ({
    _key: _keySeq++,
    _suggestedRate: null,
    _collapsed: false,
    same_skin: (item.bottom_skin_material ?? 'PPGI') === (item.top_skin_material ?? 'PPGI')
            && Number(item.bottom_skin_thickness ?? 0.40) === Number(item.top_skin_thickness ?? 0.40)
            && (item.bottom_color ?? 'Off White') === (item.top_color ?? 'Off White')
            && (item.bottom_color_ral ?? '') === (item.top_color_ral ?? '')
            && (item.bottom_surface ?? 'PLAIN') === (item.top_surface ?? 'PLAIN'),
    panel_type_id: item.panel_type_id,
    application: item.application || '',
    thickness: item.thickness,
    density_type: item.density_type || 'PUF',
    density_kgm3: item.density_kgm3 || 40,
    top_skin_material: item.top_skin_material || 'PPGI',
    top_skin_thickness: item.top_skin_thickness || 0.40,
    top_color: item.top_color || 'Off White',
    top_color_ral: item.top_color_ral || '',
    top_surface: item.top_surface || 'PLAIN',
    bottom_skin_material: item.bottom_skin_material || 'PPGI',
    bottom_skin_thickness: item.bottom_skin_thickness || 0.40,
    bottom_color: item.bottom_color || 'Off White',
    bottom_color_ral: item.bottom_color_ral || '',
    bottom_surface: item.bottom_surface || 'PLAIN',
    guard_film: !!item.guard_film,
    cello_tap: !!item.cello_tap,
    fixing_system: item.fixing_system || '',
    hsn_code: item.hsn_code || '39259010',
    sizes: (item.sizes || []).map(sz => ({
      _key: _keySeq++,
      length_mm: sz.length_mm,
      width_mm: 1000,
      nos: sz.nos,
      sqm: sz.sqm ?? (sz.length_mm / 1000) * sz.nos,
      rate_per_sqm: sz.rate_per_sqm || 0,
      amount: sz.amount || 0,
    })),
  }))

  accessories_rows.value = (q.accessories || []).map(a => ({
    _key:         _keySeq++,
    type:         a.pivot?.type || 'standard',
    accessory_id: a.id,
    description:  a.description || a.name,
    qty:          a.pivot?.quantity || 1,
    unit:         a.unit || 'NOS',
    rate:         a.pivot?.unit_price || 0,
    amount:       a.pivot?.amount || 0,
    door_type:    a.pivot?.door_type  || null,
    door_width:   a.pivot?.door_width  || null,
    door_height:  a.pivot?.door_height || null,
  }))

  recalcTotals()
  } catch (e) {
    toastError('Could not load quotation for editing: ' + (e?.response?.data?.message ?? e?.message ?? 'unknown error'))
  }
}

// ── Mount ─────────────────────────────────────────────────────────
onMounted(async () => {
  const [custRes, ptRes, accRes, stateCode] = await Promise.all([
    quotationService.customers(),
    quotationService.panelTypes(),
    quotationService.accessories(),
    quotationService.getCompanyStateCode(),
  ])
  customers.value         = custRes?.data?.data ?? custRes?.data ?? []
  panelTypes.value        = ptRes?.data?.data   ?? ptRes?.data   ?? []
  masterAccessories.value = accRes?.data?.data  ?? accRes?.data  ?? []
  companyStateCode.value  = stateCode

  if (isEdit.value) await loadForEdit()
  // Lead → quotation: prefill the customer
  if (!isEdit.value && props.prefillCustomerId) {
    form.customer_id = props.prefillCustomerId
    onCustomerChange()
  }
  // Start dirty-tracking only after initial data/edit load settles.
  await nextTick()
  watch([form, panelRows, accessories_rows], () => { dirty.value = true }, { deep: true })
})

watch([() => form.discount_pct, () => form.advance_pct, () => form.transport_fixed, () => form.transport_amount], recalcTotals)
watch(isInterState, recalcTotals)

// ── Unsaved-changes protection ───────────────────────────────────────
const dirty = ref(false)

async function onBack() {
  if (!dirty.value) { emit('cancel'); return }
  const ok = await confirmDialog({
    title: 'Discard unsaved changes?',
    message: 'You have unsaved changes on this ' + (isBoq.value ? 'BOQ' : 'quotation') + '. Leaving now will lose them.',
    confirmLabel: 'Discard',
    cancelLabel: 'Keep editing',
    danger: true,
  })
  if (ok) emit('cancel')
}

function beforeUnloadGuard(e) {
  if (dirty.value) { e.preventDefault(); e.returnValue = '' }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnloadGuard))
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnloadGuard))
</script>

<style scoped>
.qc-wrap { font-family: inherit; max-width: 1100px; margin: 0 auto; }

.qc-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.qc-header h2 { flex: 1; margin: 0; font-size: 20px; color: var(--primary); }
.header-actions { display: flex; gap: 8px; }

.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }

.qc-body { display: flex; flex-direction: column; gap: 18px; }

.card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px 24px; }
.card h3 { margin: 0 0 16px; font-size: 15px; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-tint); padding-bottom: 8px; }

.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.section-header h3 { margin: 0; border: none; padding: 0; }

.form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 16px; }
.form-grid.compact { grid-template-columns: repeat(4, 1fr); }
.full-span { grid-column: 1 / -1; }

/* Responsive: collapse multi-column grids + scroll the wide size table */
@media (max-width: 1100px) {
  .form-grid, .form-grid.compact { grid-template-columns: repeat(2, 1fr); }
  .skin-spec { grid-template-columns: 1fr; }
  .size-section { overflow-x: auto; }
  .cust-grid { grid-template-columns: 1fr; }
  .sticky-totals { gap: 16px; flex-wrap: wrap; }
}
@media (max-width: 600px) {
  .form-grid, .form-grid.compact { grid-template-columns: 1fr; }
  .qc-header { flex-wrap: wrap; }
}

.form-group label { display: block; font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
.form-group input, .form-group select, .form-group textarea {
  width: 100%; padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px;
  font-size: 13px; background: #fff; box-sizing: border-box;
}
.form-group textarea { resize: vertical; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-tint); }

.customer-row { display: flex; gap: 6px; }
.customer-row select { flex: 1; }

.transport-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.gst-indicator { margin-top: 12px; padding: 8px 14px; border-radius: 6px; font-size: 12px; background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
.gst-indicator.inter { background: var(--primary-tint); color: var(--primary); border-color: #90caf9; }

.toggle-label { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
.toggle-label input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; }

/* Panel row card */
.panel-row-card { border: 1px solid var(--primary-bd); border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; background: #fafafe; }
.panel-row-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; cursor: pointer; }
.row-toggle { background: none; border: none; color: var(--text-2); font-size: 13px; cursor: pointer; width: 18px; flex-shrink: 0; padding: 0; }
.row-num { background: var(--primary); color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
.row-summary { flex: 1; font-size: 12px; color: var(--text-2); font-style: italic; }
.row-header-actions { display: flex; gap: 4px; }

.skin-spec { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 12px 0; }
.skin-col { border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px; background: white; }
.skin-label-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.skin-label { font-size: 10px; font-weight: 700; letter-spacing: 1px; }
.skin-label-row .skin-label { margin-bottom: 0; }
.same-skin-toggle { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text-2); cursor: pointer; }
.same-skin-note { font-size: 11px; color: var(--text-3); font-style: italic; padding: 8px 2px; }
.top-label { color: var(--primary); }
.btm-label { color: #555; }

.extras-row { display: flex; align-items: center; gap: 20px; margin: 8px 0; flex-wrap: wrap; }
.inline-group { display: flex; align-items: center; gap: 8px; }
.inline-group label { white-space: nowrap; font-size: 11px; color: #888; text-transform: uppercase; }

/* Size sub-table */
.size-section { margin-top: 12px; }
.size-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 11px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; }
.size-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.size-table th { background: var(--primary-tint); color: #333; padding: 5px 8px; text-align: left; border: 1px solid var(--primary-bd); font-size: 10px; text-transform: uppercase; }
.size-table td { padding: 4px 6px; border: 1px solid #e0e0e0; vertical-align: middle; }
.size-table tr:nth-child(even) td { background: #fafafa; }
.size-total-row td { background: var(--primary-tint) !important; font-weight: 700; }

.sz-input { width: 80px; padding: 4px 6px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; text-align: right; }
.sz-input.readonly { background: #f5f5f5; color: #999; }
.nos-input { width: 60px; text-align: center; }
.rate-input { width: 90px; }
.bold-cell { font-weight: 700; text-align: right; }
.amount-cell { color: var(--primary); }
.empty-hint-sm { text-align: center; color: #aaa; font-style: italic; padding: 8px; font-size: 12px; }

.warn-tag { display: inline-block; background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; border-radius: 3px; padding: 1px 4px; font-size: 9px; margin-left: 4px; font-weight: 700; }
.suggested-tag { display: inline-block; background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; border-radius: 3px; padding: 1px 5px; font-size: 9px; margin-left: 4px; cursor: pointer; }
.suggested-tag:hover { background: #c8e6c9; }

/* Accessories */
.acc-add-btns { display: flex; gap: 6px; flex-wrap: wrap; }

.acc-row-card { border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; background: #fafafa; }
.acc-row-card.acc-door { border-color: #b39ddb; background: #f3f0ff; }

.acc-row-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.acc-type-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
.acc-type-badge.standard    { background: var(--primary-tint); color: var(--primary); }
.acc-type-badge.installation { background: #fff8e1; color: #e65100; }
.acc-type-badge.door        { background: #ede7f6; color: #4527a0; }
.acc-del-btn { color: #c62828; font-size: 11px; font-weight: 600; }

.acc-fields { margin-top: 0; }
.readonly-field { background: #f5f5f5 !important; color: #666 !important; }
.readonly-field.bold { font-weight: 700; color: var(--primary) !important; }

.door-spec { }
.door-dimensions { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.dim-badge { background: #ede7f6; color: #4527a0; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 10px; }
.door-desc-input { flex: 1; min-width: 200px; padding: 5px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; color: #555; }

.acc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.acc-table th { background: var(--primary-tint); color: #333; padding: 6px 10px; text-align: left; font-size: 11px; text-transform: uppercase; border: 1px solid var(--primary-bd); }
.acc-table td { padding: 5px 8px; border: 1px solid #e0e0e0; vertical-align: middle; }

/* Totals */
.totals-card { max-width: 480px; margin-left: auto; }
.totals-grid { display: flex; flex-direction: column; gap: 0; }
.t-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.t-row.border-top { border-top: 2px solid var(--primary); margin-top: 4px; padding-top: 9px; font-weight: 600; }
.t-row.discount { color: #c62828; }
.t-row.grand { background: var(--primary); color: white; font-size: 15px; font-weight: 700; padding: 10px 8px; border-radius: 6px; margin: 6px 0; }
.t-row.balance { color: var(--primary); font-weight: 700; }

/* DL legend above the size table */
.dl-legend { margin: 0 0 8px; font-size: 11px; color: var(--text-2); line-height: 1.5; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 6px 10px; }
.dl-legend .warn-tag { font-size: 9px; }

/* Fixed-width size cell (panel module width is always 1m) */
.width-fixed { color: var(--text-3); font-variant-numeric: tabular-nums; white-space: nowrap; }
.wf-unit { font-size: 10px; }

/* New customer modal */
.cust-overlay { position: fixed; inset: 0; background: rgba(16,24,40,0.45); display: flex; align-items: center; justify-content: center; z-index: 9000; }
.cust-modal { background: var(--surface); border-radius: var(--r-lg); padding: 22px 24px; width: 520px; max-width: calc(100vw - 32px); box-shadow: var(--shadow-lg); }
.cust-modal h3 { margin: 0 0 16px; font-size: 16px; border: none; padding: 0; }
.cust-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
.cust-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

/* Sticky running totals bar */
.sticky-totals {
  position: sticky; bottom: 0; z-index: 5;
  display: flex; align-items: center; gap: 26px;
  margin: 16px 0 0; padding: 11px 20px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-lg); box-shadow: var(--shadow-md);
}
.st-item { display: flex; flex-direction: column; font-size: 14px; font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; }
.st-item label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-3); margin-bottom: 1px; }
.st-grand { color: var(--primary); font-size: 16px; }
.st-actions { margin-left: auto; display: flex; gap: 8px; }

/* BOQ mode */
.boq-mode-tag { display: inline-flex; align-items: center; background: var(--warning-bg, #fff8e1); color: var(--warning, #e65100); border: 1px solid var(--warning-bd, #ffcc80); border-radius: 12px; padding: 4px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
.boq-summary .boq-grand { background: var(--primary, var(--primary)); }
.boq-note { margin: 10px 2px 0; font-size: 11px; color: #888; font-style: italic; line-height: 1.4; }

/* Buttons */
.btn { padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { background: var(--primary-tint); color: var(--primary); }
.btn-ghost { background: transparent; border: 1px solid #ddd; color: #555; }

.btn-sm { padding: 3px 9px; border: none; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; }
.btn-add  { background: #e8f5e9; color: #2e7d32; }
.btn-dup  { background: var(--primary-tint); color: var(--primary); }
.btn-del  { background: #ffebee; color: #c62828; }
.btn-add-size { background: var(--primary-tint); color: var(--primary); }
.btn-sz-del { background: none; border: none; color: #aaa; cursor: pointer; font-size: 13px; line-height: 1; padding: 2px 4px; }
.btn-sz-del:hover { color: #c62828; }

.empty-hint { text-align: center; color: #aaa; font-style: italic; padding: 20px; font-size: 13px; border: 2px dashed #e0e0e0; border-radius: 8px; }
.text-muted { color: #aaa; }
</style>
