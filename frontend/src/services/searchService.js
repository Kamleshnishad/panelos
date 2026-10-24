import { api } from './api.js'

export default {
  /** Records matching `term` in the user's company, grouped by kind. */
  search(term) { return api.get('/search', { params: { q: term } }).then(r => r.data) },
}
