import api from './api'

export async function getScanJobs(config = {}) {
  const response = await api.get('/api/scan-jobs', config)
  return response.data.scan_jobs
}

export async function createScanJob(payload) {
  const response = await api.post('/api/scan-jobs', payload)
  return response.data.scan_job
}

export async function rerunScanJob(id) {
  const response = await api.post(`/api/scan-jobs/${id}/rerun`)
  return response.data.scan_job
}

export async function processScanJobSimulated(id) {
  const response = await api.post(`/api/scan-jobs/${id}/process-simulated`)
  return response.data.scan_job
}

export async function runGuardedEngine(id, engineKey) {
  const response = await api.post(`/api/scan-jobs/${id}/engines/${engineKey}/run-guarded`)
  return response.data
}
