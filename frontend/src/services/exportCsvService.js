/**
 * Utility to export findings array to a formatted CSV file and trigger download.
 */
export function exportFindingsToCsv(findings, filenamePrefix = 'tameng-findings') {
  if (!findings || !findings.length) {
    alert('Tidak ada data temuan untuk diekspor.')
    return
  }

  const headers = [
    'Kode Temuan',
    'Tingkat Keparahan',
    'Judul Kerentanan',
    'Rule ID / Scanner',
    'Proyek',
    'Lokasi File / Endpoint',
    'Baris Mulai',
    'Baris Selesai',
    'CWE',
    'OWASP',
    'CVSS',
    'Status Triage',
    'Catatan Mitigasi',
    'Tanggal Ditemukan',
  ]

  const rows = findings.map((f) => {
    const filePath = f.location?.file_path ?? f.file_path ?? '-'
    const endpoint = f.location?.endpoint ?? f.endpoint ?? ''
    const location = filePath !== '-' ? filePath : endpoint

    const lineStart = f.location?.line_start ?? f.line_start ?? '-'
    const lineEnd = f.location?.line_end ?? f.line_end ?? '-'
    const projectName = f.project?.name ?? f.project?.code ?? '-'
    const triageNotes = f.normalization_metadata?.triage_notes ?? '-'

    return [
      escapeCsv(f.code ?? ''),
      escapeCsv((f.severity ?? 'low').toUpperCase()),
      escapeCsv(f.title ?? ''),
      escapeCsv(f.rule_id ?? f.engine_key ?? ''),
      escapeCsv(projectName),
      escapeCsv(location),
      escapeCsv(String(lineStart)),
      escapeCsv(String(lineEnd)),
      escapeCsv(f.cwe ?? '-'),
      escapeCsv(f.owasp ?? '-'),
      escapeCsv(String(f.cvss ?? '-')),
      escapeCsv((f.status ?? 'open').toUpperCase()),
      escapeCsv(triageNotes),
      escapeCsv(f.created_at ? new Date(f.created_at).toISOString().replace('T', ' ').slice(0, 19) : '-'),
    ]
  })

  const csvContent = [headers.join(','), ...rows.map((r) => r.join(','))].join('\r\n')
  const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  const dateStr = new Date().toISOString().slice(0, 10)
  link.setAttribute('href', url)
  link.setAttribute('download', `${filenamePrefix}-${dateStr}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

function escapeCsv(value) {
  if (value === null || value === undefined) return '""'
  const str = String(value).replace(/"/g, '""')
  return `"${str}"`
}
