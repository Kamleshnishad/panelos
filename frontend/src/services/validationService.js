import { api } from './api.js'

export function checkUnique({ table, column, value, ignoreId = null }) {
  return api.post('/validation/check-unique', {
    table,
    column,
    value: String(value),
    ignore_id: ignoreId,
  })
}
