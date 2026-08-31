import api from './api'

export async function getAuditLogs(config = {}) {
  const response = await api.get('/api/audit-logs', config)
  return response.data.audit_logs
}
