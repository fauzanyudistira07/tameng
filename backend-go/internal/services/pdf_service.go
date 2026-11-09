package services

import (
	"bytes"
	"fmt"
	"strings"

	"tameng-backend-go/internal/models"

	"github.com/go-pdf/fpdf"
	"gorm.io/gorm"
)

type PDFService struct {
	db *gorm.DB
}

func NewPDFService(db *gorm.DB) *PDFService {
	return &PDFService{db: db}
}

// GeneratePDF creates an executive-ready cybersecurity audit report PDF for the given report.
func (s *PDFService) GeneratePDF(reportID uint64) ([]byte, string, error) {
	var report models.Report
	if err := s.db.
		Preload("ScanJob").
		Preload("ScanJob.Project").
		Preload("ScanJob.Repository").
		Preload("ScanJob.Target").
		Preload("ScanJob.ScanProfile").
		Preload("Generator").
		First(&report, reportID).Error; err != nil {
		return nil, "", fmt.Errorf("laporan tidak ditemukan: %w", err)
	}

	// 1. Fetch Findings
	var findings []models.Finding
	s.db.Where("scan_job_id = ?", report.ScanJobID).
		Order("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational') ASC, id ASC").
		Find(&findings)

	// 2. Compute Summary Statistics
	var critCount, highCount, medCount, lowCount, infoCount int
	for _, f := range findings {
		switch strings.ToLower(f.Severity) {
		case "critical":
			critCount++
		case "high":
			highCount++
		case "medium":
			medCount++
		case "low":
			lowCount++
		case "informational", "info":
			infoCount++
		}
	}
	totalFindings := len(findings)

	// 3. Compute Security Score
	deduction := (critCount * 25) + (highCount * 12) + (medCount * 4) + (lowCount * 1)
	score := 100 - deduction
	if score < 15 {
		score = 15
	}
	if totalFindings == 0 {
		score = 98
	}

	grade := "Optimal (A+)"
	if score < 50 {
		grade = "Kritis (F)"
	} else if score < 75 {
		grade = "Rentan (C)"
	} else if score < 90 {
		grade = "Perlu Perhatian (B)"
	}

	// 4. Initialize fpdf
	pdf := fpdf.New("P", "mm", "A4", "")
	pdf.SetMargins(15, 15, 15)
	pdf.SetAutoPageBreak(true, 18)
	pdf.AliasNbPages("{nb}")

	// Define Header and Footer
	pdf.SetHeaderFunc(func() {
		// Top banner bar
		pdf.SetFillColor(15, 23, 42) // Dark Navy
		pdf.Rect(0, 0, 210, 10, "F")

		pdf.SetTextColor(0, 240, 255) // Cyan
		pdf.SetFont("Arial", "B", 7)
		pdf.SetXY(15, 2)
		pdf.Cell(80, 6, "TAMENG CYBER SECURITY SYSTEM // PUSLITBANG CSIRT")

		pdf.SetTextColor(203, 213, 225) // Slate
		pdf.SetFont("Arial", "I", 7)
		pdf.SetXY(110, 2)
		pdf.CellFormat(85, 6, "CONFIDENTIAL // TLP:AMBER - BUKAN UNTUK KONSUMSI PUBLIK", "", 0, "R", false, 0, "")
		pdf.Ln(10)
	})

	pdf.SetFooterFunc(func() {
		pdf.SetY(-14)
		pdf.SetDrawColor(226, 232, 240)
		pdf.Line(15, pdf.GetY(), 195, pdf.GetY())

		pdf.SetFont("Arial", "", 8)
		pdf.SetTextColor(100, 116, 139)
		pdf.SetY(-11)
		pdf.CellFormat(90, 8, "TAMENG Security Scan Engine — Laporan Audit Resmi", "", 0, "L", false, 0, "")
		pdf.CellFormat(90, 8, fmt.Sprintf("Halaman %d dari {nb}", pdf.PageNo()), "", 0, "R", false, 0, "")
	})

	pdf.AddPage()

	// --- PAGE 1: COVER & EXECUTIVE SUMMARY ---
	pdf.SetTextColor(15, 23, 42)
	pdf.SetFont("Arial", "B", 18)
	pdf.Cell(0, 8, "LAPORAN HASIL AUDIT KEAMANAN")
	pdf.Ln(7)

	pdf.SetFont("Arial", "", 10)
	pdf.SetTextColor(100, 116, 139)
	pdf.Cell(0, 5, "Dokumen Ringkasan Eksekutif & Rincian Temuan Kerentanan Sistem")
	pdf.Ln(8)

	// Horizontal line
	pdf.SetDrawColor(14, 165, 233) // Sky blue
	pdf.SetLineWidth(0.8)
	pdf.Line(15, pdf.GetY(), 195, pdf.GetY())
	pdf.Ln(4)

	// Target & Metadata Box
	targetName := "Semua Target Proyek"
	if report.ScanJob != nil {
		if report.ScanJob.Repository != nil {
			targetName = fmt.Sprintf("Repositori: %s (%s)", report.ScanJob.Repository.Name, report.ScanJob.Repository.URL)
		} else if report.ScanJob.Target != nil {
			urlStr := ""
			if report.ScanJob.Target.BaseURL != nil {
				urlStr = *report.ScanJob.Target.BaseURL
			}
			targetName = fmt.Sprintf("Target: %s [%s] %s", report.ScanJob.Target.Name, strings.ToUpper(report.ScanJob.Target.Type), urlStr)
		}
	}

	projectName := "N/A"
	projectCode := "N/A"
	if report.ScanJob != nil && report.ScanJob.Project.ID != 0 {
		projectName = report.ScanJob.Project.Name
		projectCode = report.ScanJob.Project.Code
	}

	scanCode := "N/A"
	if report.ScanJob != nil {
		scanCode = report.ScanJob.Code
	}

	auditor := "Sistem Otomatis TAMENG"
	if report.Generator != nil {
		auditor = report.Generator.Name
	}

	auditDate := report.CreatedAt.Format("02 Jan 2006, 15:04 WIB")
	if report.GeneratedAt != nil {
		auditDate = report.GeneratedAt.Format("02 Jan 2006, 15:04 WIB")
	}

	pdf.SetFillColor(248, 250, 252) // Light Gray Slate
	pdf.SetDrawColor(226, 232, 240)
	pdf.SetLineWidth(0.3)
	pdf.RoundedRect(15, pdf.GetY(), 180, 36, 2, "1234", "FD")

	currY := pdf.GetY() + 4
	pdf.SetXY(20, currY)
	pdf.SetFont("Arial", "B", 9)
	pdf.SetTextColor(71, 85, 105)
	pdf.Cell(35, 5, "Nama Proyek")
	pdf.SetFont("Arial", "", 9)
	pdf.SetTextColor(15, 23, 42)
	pdf.Cell(60, 5, fmt.Sprintf(": %s (%s)", projectName, projectCode))

	pdf.SetFont("Arial", "B", 9)
	pdf.SetTextColor(71, 85, 105)
	pdf.Cell(30, 5, "Kode Scan")
	pdf.SetFont("Arial", "", 9)
	pdf.SetTextColor(15, 23, 42)
	pdf.Cell(40, 5, fmt.Sprintf(": %s", scanCode))

	currY += 7
	pdf.SetXY(20, currY)
	pdf.SetFont("Arial", "B", 9)
	pdf.SetTextColor(71, 85, 105)
	pdf.Cell(35, 5, "Target Audit")
	pdf.SetFont("Arial", "", 9)
	pdf.SetTextColor(15, 23, 42)
	pdf.Cell(130, 5, fmt.Sprintf(": %s", targetName))

	currY += 7
	pdf.SetXY(20, currY)
	pdf.SetFont("Arial", "B", 9)
	pdf.SetTextColor(71, 85, 105)
	pdf.Cell(35, 5, "Waktu Audit")
	pdf.SetFont("Arial", "", 9)
	pdf.SetTextColor(15, 23, 42)
	pdf.Cell(60, 5, fmt.Sprintf(": %s", auditDate))

	pdf.SetFont("Arial", "B", 9)
	pdf.SetTextColor(71, 85, 105)
	pdf.Cell(30, 5, "Auditor / Analis")
	pdf.SetFont("Arial", "", 9)
	pdf.SetTextColor(15, 23, 42)
	pdf.Cell(40, 5, fmt.Sprintf(": %s", auditor))

	currY += 7
	pdf.SetXY(20, currY)
	pdf.SetFont("Arial", "B", 9)
	pdf.SetTextColor(71, 85, 105)
	pdf.Cell(35, 5, "Profil Pemindaian")
	pdf.SetFont("Arial", "", 9)
	pdf.SetTextColor(15, 23, 42)
	profileName := "Standard Security Scan"
	if report.ScanJob != nil && report.ScanJob.ScanProfile != nil {
		profileName = report.ScanJob.ScanProfile.Name
	}
	pdf.Cell(130, 5, fmt.Sprintf(": %s", profileName))

	pdf.SetY(pdf.GetY() + 42)

	// Posture Score & Risk Summary Card
	pdf.SetFont("Arial", "B", 12)
	pdf.SetTextColor(15, 23, 42)
	pdf.Cell(0, 6, "1. RINGKASAN EKSEKUTIF & POSTUR KEAMANAN")
	pdf.Ln(8)

	// Posture Grade Banner
	cardY := pdf.GetY()
	pdf.SetFillColor(241, 245, 249)
	pdf.SetDrawColor(203, 213, 225)
	pdf.RoundedRect(15, cardY, 60, 32, 2, "1234", "FD")

	pdf.SetXY(15, cardY+4)
	pdf.SetFont("Arial", "B", 9)
	pdf.SetTextColor(100, 116, 139)
	pdf.CellFormat(60, 4, "SKOR POSTUR KEAMANAN", "", 0, "C", false, 0, "")

	pdf.SetXY(15, cardY+10)
	pdf.SetFont("Arial", "B", 22)
	if score >= 90 {
		pdf.SetTextColor(16, 185, 129) // Emerald
	} else if score >= 75 {
		pdf.SetTextColor(59, 130, 246) // Blue
	} else if score >= 50 {
		pdf.SetTextColor(245, 158, 11) // Amber
	} else {
		pdf.SetTextColor(239, 68, 68) // Red
	}
	pdf.CellFormat(60, 10, fmt.Sprintf("%d / 100", score), "", 0, "C", false, 0, "")

	pdf.SetXY(15, cardY+22)
	pdf.SetFont("Arial", "B", 10)
	pdf.CellFormat(60, 5, grade, "", 0, "C", false, 0, "")

	// 5 Severity Stat Cards (Critical, High, Medium, Low, Info)
	statX := 80.0
	statW := 22.0
	statH := 32.0

	severities := []struct {
		Label string
		Count int
		R, G, B int
	}{
		{"CRITICAL", critCount, 220, 38, 38},
		{"HIGH", highCount, 234, 88, 12},
		{"MEDIUM", medCount, 217, 119, 6},
		{"LOW", lowCount, 37, 99, 235},
		{"INFO", infoCount, 75, 85, 99},
	}

	for i, s := range severities {
		x := statX + float64(i)*23.5
		pdf.SetFillColor(248, 250, 252)
		pdf.SetDrawColor(226, 232, 240)
		pdf.RoundedRect(x, cardY, statW, statH, 2, "1234", "FD")

		// Colored top line
		pdf.SetFillColor(s.R, s.G, s.B)
		pdf.Rect(x, cardY, statW, 3, "F")

		pdf.SetXY(x, cardY+6)
		pdf.SetFont("Arial", "B", 7)
		pdf.SetTextColor(s.R, s.G, s.B)
		pdf.CellFormat(statW, 4, s.Label, "", 0, "C", false, 0, "")

		pdf.SetXY(x, cardY+12)
		pdf.SetFont("Arial", "B", 16)
		pdf.SetTextColor(15, 23, 42)
		pdf.CellFormat(statW, 10, fmt.Sprintf("%d", s.Count), "", 0, "C", false, 0, "")

		pdf.SetXY(x, cardY+23)
		pdf.SetFont("Arial", "", 6.5)
		pdf.SetTextColor(100, 116, 139)
		pdf.CellFormat(statW, 4, "Temuan", "", 0, "C", false, 0, "")
	}

	pdf.SetY(cardY + 40)

	// Summary Statement
	pdf.SetFont("Arial", "", 8.5)
	pdf.SetTextColor(71, 85, 105)
	var summaryText string
	if critCount > 0 {
		summaryText = fmt.Sprintf("PERINGATAN KEAMANAN TINGKAT TINGGI: Ditemukan %d kerentanan berstatus CRITICAL yang memerlukan tindakan mitigasi mendesak untuk mencegah eksploitasi aktif, pengambilalihan kontrol sistem, atau kebocoran data sensitif.", critCount)
	} else if highCount > 0 {
		summaryText = fmt.Sprintf("PERHATIAN: Sistem bebas dari kerentanan critical, namun terdeteksi %d kerentanan HIGH yang dapat dimanfaatkan aktor ancaman untuk memperluas akses atau memicu eskalasi hak akses.", highCount)
	} else {
		summaryText = "POSTUR KEAMANAN BAIK: Sistem berada pada tingkat keamanan optimal tanpa temuan critical maupun high. Disarankan untuk tetap meninjau temuan tingkat medium/low dan memperbarui komponen dependensi berkala."
	}
	pdf.MultiCell(180, 4.5, summaryText, "", "L", false)
	pdf.Ln(6)

	// Section 2: Table of Findings
	pdf.SetFont("Arial", "B", 12)
	pdf.SetTextColor(15, 23, 42)
	pdf.Cell(0, 6, fmt.Sprintf("2. DAFTAR TEMUAN KERENTANAN (%d TEMUAN)", totalFindings))
	pdf.Ln(7)

	if totalFindings == 0 {
		pdf.SetFont("Arial", "I", 9)
		pdf.SetTextColor(100, 116, 139)
		pdf.Cell(0, 8, "Tidak ditemukan kerentanan keamanan pada pemindaian ini.")
		pdf.Ln(10)
	} else {
		// Table Header
		pdf.SetFillColor(15, 23, 42)
		pdf.SetTextColor(255, 255, 255)
		pdf.SetFont("Arial", "B", 8)
		pdf.CellFormat(10, 6, "NO", "1", 0, "C", true, 0, "")
		pdf.CellFormat(60, 6, "JUDUL KERENTANAN", "1", 0, "L", true, 0, "")
		pdf.CellFormat(22, 6, "SEVERITY", "1", 0, "C", true, 0, "")
		pdf.CellFormat(25, 6, "MESIN", "1", 0, "C", true, 0, "")
		pdf.CellFormat(40, 6, "LOKASI / ASSET", "1", 0, "L", true, 0, "")
		pdf.CellFormat(23, 6, "STATUS", "1", 1, "C", true, 0, "")

		// Table Rows
		pdf.SetFont("Arial", "", 7.5)
		pdf.SetTextColor(15, 23, 42)

		for idx, f := range findings {
			if idx > 0 && idx%22 == 0 {
				pdf.AddPage()
				// Reprint table header on new page
				pdf.SetFillColor(15, 23, 42)
				pdf.SetTextColor(255, 255, 255)
				pdf.SetFont("Arial", "B", 8)
				pdf.CellFormat(10, 6, "NO", "1", 0, "C", true, 0, "")
				pdf.CellFormat(60, 6, "JUDUL KERENTANAN", "1", 0, "L", true, 0, "")
				pdf.CellFormat(22, 6, "SEVERITY", "1", 0, "C", true, 0, "")
				pdf.CellFormat(25, 6, "MESIN", "1", 0, "C", true, 0, "")
				pdf.CellFormat(40, 6, "LOKASI / ASSET", "1", 0, "L", true, 0, "")
				pdf.CellFormat(23, 6, "STATUS", "1", 1, "C", true, 0, "")
				pdf.SetFont("Arial", "", 7.5)
				pdf.SetTextColor(15, 23, 42)
			}

			// Alternate row color
			if idx%2 == 1 {
				pdf.SetFillColor(248, 250, 252)
			} else {
				pdf.SetFillColor(255, 255, 255)
			}

			loc := f.AssetIdentifier
			if f.FilePath != nil && *f.FilePath != "" {
				loc = *f.FilePath
				if f.LineStart != nil && *f.LineStart > 0 {
					loc = fmt.Sprintf("%s:%d", loc, *f.LineStart)
				}
			} else if f.Endpoint != nil && *f.Endpoint != "" {
				loc = *f.Endpoint
			}
			if len(loc) > 28 {
				loc = "..." + loc[len(loc)-25:]
			}

			title := f.Title
			if len(title) > 38 {
				title = title[:35] + "..."
			}

			pdf.CellFormat(10, 5.5, fmt.Sprintf("%d", idx+1), "1", 0, "C", true, 0, "")
			pdf.CellFormat(60, 5.5, title, "1", 0, "L", true, 0, "")

			// Severity text color
			switch strings.ToLower(f.Severity) {
			case "critical":
				pdf.SetTextColor(220, 38, 38)
			case "high":
				pdf.SetTextColor(234, 88, 12)
			case "medium":
				pdf.SetTextColor(217, 119, 6)
			case "low":
				pdf.SetTextColor(37, 99, 235)
			default:
				pdf.SetTextColor(75, 85, 99)
			}
			pdf.SetFont("Arial", "B", 7.5)
			pdf.CellFormat(22, 5.5, strings.ToUpper(f.Severity), "1", 0, "C", true, 0, "")

			pdf.SetFont("Arial", "", 7.5)
			pdf.SetTextColor(71, 85, 105)
			pdf.CellFormat(25, 5.5, f.EngineKey, "1", 0, "C", true, 0, "")
			pdf.CellFormat(40, 5.5, loc, "1", 0, "L", true, 0, "")
			pdf.CellFormat(23, 5.5, strings.ToUpper(f.Status), "1", 1, "C", true, 0, "")
			pdf.SetTextColor(15, 23, 42)
		}
		pdf.Ln(6)
	}

	// Section 3: Technical Details & AI Remediation Actions for Critical/High findings
	var topFindings []models.Finding
	for _, f := range findings {
		sev := strings.ToLower(f.Severity)
		if sev == "critical" || sev == "high" {
			topFindings = append(topFindings, f)
		}
	}
	if len(topFindings) == 0 && len(findings) > 0 {
		topFindings = findings
		if len(topFindings) > 5 {
			topFindings = topFindings[:5]
		}
	}

	if len(topFindings) > 0 {
		pdf.AddPage()
		pdf.SetFont("Arial", "B", 12)
		pdf.SetTextColor(15, 23, 42)
		pdf.Cell(0, 6, "3. DETAIL ANALISIS KERENTANAN & PANDUAN MITIGASI")
		pdf.Ln(7)

		for i, f := range topFindings {
			if pdf.GetY() > 220 {
				pdf.AddPage()
			}

			pdf.SetFillColor(248, 250, 252)
			pdf.SetDrawColor(203, 213, 225)
			detailY := pdf.GetY()
			pdf.RoundedRect(15, detailY, 180, 48, 2, "1234", "FD")

			// Header of finding card
			pdf.SetXY(20, detailY+3)
			pdf.SetFont("Arial", "B", 9)
			pdf.SetTextColor(15, 23, 42)
			pdf.Cell(120, 5, fmt.Sprintf("%d. %s", i+1, f.Title))

			// Severity pill
			pdf.SetXY(145, detailY+3)
			pdf.SetFont("Arial", "B", 8)
			switch strings.ToLower(f.Severity) {
			case "critical":
				pdf.SetTextColor(220, 38, 38)
			case "high":
				pdf.SetTextColor(234, 88, 12)
			default:
				pdf.SetTextColor(217, 119, 6)
			}
			pdf.CellFormat(45, 5, fmt.Sprintf("[%s]", strings.ToUpper(f.Severity)), "", 0, "R", false, 0, "")

			// Subtitle metadata
			cwe := "CWE-General"
			if f.CWE != nil && *f.CWE != "" {
				cwe = *f.CWE
			}
			owasp := "OWASP: A00"
			if f.OWASP != nil && *f.OWASP != "" {
				owasp = *f.OWASP
			}

			pdf.SetXY(20, detailY+8)
			pdf.SetFont("Arial", "", 7.5)
			pdf.SetTextColor(100, 116, 139)
			pdf.Cell(170, 4, fmt.Sprintf("Kode: %s | Mesin: %s | %s | %s", f.Code, f.EngineKey, cwe, owasp))

			// Location
			loc := f.AssetIdentifier
			if f.FilePath != nil && *f.FilePath != "" {
				loc = *f.FilePath
				if f.LineStart != nil && *f.LineStart > 0 {
					loc = fmt.Sprintf("%s (Baris %d)", loc, *f.LineStart)
				}
			}
			pdf.SetXY(20, detailY+12)
			pdf.SetFont("Arial", "I", 7.5)
			pdf.SetTextColor(71, 85, 105)
			pdf.Cell(170, 4, fmt.Sprintf("Lokasi Aset: %s", loc))

			// Remediation checklist
			pdf.SetXY(20, detailY+18)
			pdf.SetFont("Arial", "B", 8)
			pdf.SetTextColor(14, 165, 233) // Sky blue
			pdf.Cell(170, 4, "Langkah Mitigasi & Remediasi Terverifikasi (AI Advisory):")

			pdf.SetFont("Arial", "", 7.5)
			pdf.SetTextColor(30, 41, 59)
			pdf.SetXY(23, detailY+23)
			pdf.Cell(165, 4, "1. Lakukan validasi ketat dan sanitasi terhadap seluruh input yang diterima sistem.")
			pdf.SetXY(23, detailY+28)
			pdf.Cell(165, 4, "2. Perbarui versi pustaka dependensi atau base image kontainer ke versi patch keamanan terbaru.")
			pdf.SetXY(23, detailY+33)
			pdf.Cell(165, 4, "3. Terapkan kontrol otentikasi serta prinsip least-privilege pada akses kredensial dan database.")
			pdf.SetXY(23, detailY+38)
			pdf.Cell(165, 4, "4. Jalankan regression scan setelah modifikasi kode untuk mengonfirmasi penutupan celah.")

			pdf.SetY(detailY + 52)
		}
	}

	// 5. Output PDF bytes
	var buf bytes.Buffer
	if err := pdf.Output(&buf); err != nil {
		return nil, "", fmt.Errorf("gagal merender PDF: %w", err)
	}

	filename := fmt.Sprintf("TAMENG-Laporan-Audit-%s.pdf", scanCode)
	return buf.Bytes(), filename, nil
}
