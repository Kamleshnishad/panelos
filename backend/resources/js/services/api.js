// Simple API wrapper using fetch
const API_BASE_URL = '/api'

// Get CSRF token from meta tag
const getCsrfToken = () => {
  const token = document.querySelector('meta[name="csrf-token"]')
  return token ? token.getAttribute('content') : ''
}

// Get authentication token from localStorage
const getAuthToken = () => {
  return localStorage.getItem('auth_token')
}

// Helper to build query string
const buildQueryString = (params) => {
  if (!params || Object.keys(params).length === 0) return ''
  const query = new URLSearchParams()
  Object.keys(params).forEach(key => {
    if (params[key] !== null && params[key] !== undefined && params[key] !== '') {
      query.append(key, params[key])
    }
  })
  const qs = query.toString()
  return qs ? `?${qs}` : ''
}

// Make request function
const request = async (method, url, data = null, params = null) => {
  const fullUrl = `${API_BASE_URL}${url}${buildQueryString(params)}`

  const options = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken()
    }
  }

  const authToken = getAuthToken()
  if (authToken) {
    options.headers['Authorization'] = `Bearer ${authToken}`
  }

  if (data) {
    options.body = JSON.stringify(data)
  }

  try {
    const response = await fetch(fullUrl, options)

    // Handle 401 Unauthorized
    if (response.status === 401) {
      localStorage.removeItem('auth_token')
      window.location.href = '/login'
      return
    }

    const responseData = await response.json()

    if (!response.ok) {
      const error = new Error(responseData.message || 'Request failed')
      error.response = {
        status: response.status,
        data: responseData
      }
      throw error
    }

    return {
      data: responseData,
      status: response.status,
      headers: response.headers
    }
  } catch (error) {
    if (!error.response) {
      error.response = {
        status: 0,
        data: { message: error.message }
      }
    }
    throw error
  }
}

export default {
  get(url, config = {}) {
    return request('GET', url, null, config.params)
  },

  post(url, data = null, config = {}) {
    return request('POST', url, data, config.params)
  },

  put(url, data = null, config = {}) {
    return request('PUT', url, data, config.params)
  },

  patch(url, data = null, config = {}) {
    return request('PATCH', url, data, config.params)
  },

  delete(url, config = {}) {
    return request('DELETE', url, null, config.params)
  }
}
