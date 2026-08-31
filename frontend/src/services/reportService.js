import api from './api'

export async function getReports(config = {}) {
  const response = await api.get('/api/reports', config)
  return response.data.reports
}

export async function createReport(payload) {
  const response = await api.post('/api/reports', payload)
  return response.data.report
}

export async function getReport(id, config = {}) {
  const response = await api.get(`/api/reports/${id}`, config)
  return response.data.report
}

export async function downloadReportPdf(id) {
  const response = await api.get(`/api/reports/${id}/download-pdf`, {
    responseType: 'blob',
    skipCache: true,
  })

  return response.data
}
