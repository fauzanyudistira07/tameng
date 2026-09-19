<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { apiFetch } from "../services/api";
import { useAuth } from "../composables/useAuth";

const { currentUser, loadUser } = useAuth();

interface FindingProject {
  id: number;
  name: string;
  code: string;
}

interface FindingScanJob {
  id: number;
  code: string;
  status: string;
}

interface FindingScanRun {
  id: number;
  engine_key: string;
  status: string;
}

interface FindingItem {
  id: number;
  code: string;
  project_id: number;
  scan_job_id: number;
  scan_run_id?: number;
  title: string;
  severity: "critical" | "high" | "medium" | "low" | "informational";
  cve?: string | null;
  cwe?: string | null;
  status:
    | "open"
    | "reviewing"
    | "in_progress"
    | "resolved"
    | "false_positive"
    | "accepted"
    | "fixed";
  description?: string;
  solution?: string;
  location?: string;
  evidence?: any;
  discovered_at?: string;
  normalization_metadata?: any;
  project?: FindingProject;
  scan_job?: FindingScanJob;
  scanJob?: FindingScanJob;
  scan_run?: FindingScanRun;
  scanRun?: FindingScanRun;
}

interface FindingSummary {
  total: number;
  critical: number;
  high: number;
  medium: number;
  low: number;
  informational: number;
}

const findings = ref<FindingItem[]>([]);
const summary = ref<FindingSummary>({
  total: 0,
  critical: 0,
  high: 0,
  medium: 0,
  low: 0,
  informational: 0,
});
const isLoading = ref(true);

// View Mode: 'task' (Developer Issue Tracker) or 'table' (SOC Table)
const viewMode = ref<"task" | "table">("task");

// Task pipeline status filter for developer view
const taskStatusFilter = ref<"open_pending" | "in_progress" | "completed" | "all">("open_pending");

const taskCounts = computed(() => {
  const all = findings.value;
  return {
    open_pending: all.filter((f) => f.status === "open" || f.status === "reviewing").length,
    in_progress: all.filter((f) => f.status === "in_progress").length,
    completed: all.filter((f) => f.status === "fixed" || f.status === "resolved").length,
    all: all.length,
  };
});

// Quick Mark as Fixed modal state
const isMarkFixedModalOpen = ref(false);
const targetFindingToFix = ref<FindingItem | null>(null);
const fixResolutionNotes = ref("");
const fixStatusChoice = ref<"fixed" | "in_progress">("fixed");
const isSavingFixStatus = ref(false);
const fixStatusError = ref<string | null>(null);

function openMarkFixedModal(finding: FindingItem) {
  targetFindingToFix.value = finding;
  fixStatusChoice.value = "fixed";
  fixResolutionNotes.value = "";
  fixStatusError.value = null;
  isMarkFixedModalOpen.value = true;
}

function closeMarkFixedModal() {
  isMarkFixedModalOpen.value = false;
  targetFindingToFix.value = null;
  fixResolutionNotes.value = "";
  fixStatusError.value = null;
}

async function submitMarkFixed() {
  if (!targetFindingToFix.value) return;
  isSavingFixStatus.value = true;
  fixStatusError.value = null;
  try {
    const payload = {
      status: fixStatusChoice.value,
      resolution_notes: fixResolutionNotes.value.trim() || "Diperbaiki oleh developer",
    };
    const res = await apiFetch(`/api/findings/${targetFindingToFix.value.id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
    if (res?.finding) {
      const idx = findings.value.findIndex((f) => f.id === targetFindingToFix.value?.id);
      if (idx !== -1) {
        findings.value[idx].status = res.finding.status;
      }
      closeMarkFixedModal();
    } else {
      fixStatusError.value = res?.message || "Gagal memperbarui status perbaikan.";
    }
  } catch (err: any) {
    fixStatusError.value = err?.message || "Terjadi kesalahan saat menyimpan status perbaikan.";
  } finally {
    isSavingFixStatus.value = false;
  }
}

// Helper salin path file
const copiedPathId = ref<number | null>(null);
function copyFilePath(path?: string, id?: number) {
  if (!path || !id) return;
  navigator.clipboard.writeText(path);
  copiedPathId.value = id;
  setTimeout(() => {
    if (copiedPathId.value === id) copiedPathId.value = null;
  }, 2000);
}

function getLocationDisplay(f: FindingItem) {
  if (f.location) return f.location;
  if (f.normalization_metadata?.location?.file_path) {
    const p = f.normalization_metadata.location.file_path;
    const l = f.normalization_metadata.location.line_start;
    return l ? `${p}:${l}` : p;
  }
  return null;
}

// Filter states
const searchQuery = ref("");
const filterSeverity = ref<string>("all");
const filterStatus = ref<string>("all");
const filterProject = ref<string>("all");

function resetFilters() {
  searchQuery.value = "";
  filterSeverity.value = "all";
  filterStatus.value = "all";
  filterProject.value = "all";
}

// Modal Detail / AI Remediation state
const isModalOpen = ref(false);
const modalTab = ref<"details" | "ai" | "triage">("details");
const activeFinding = ref<FindingItem | null>(null);
const aiRemediationText = ref<string | null>(null);
const isLoadingAi = ref(false);

// Triage form state
const triageStatus = ref<string>("open");
const triageNotes = ref<string>("");
const isSavingTriage = ref(false);
const triageMessage = ref<{ type: "success" | "danger"; text: string } | null>(
  null,
);

const canTriage = computed(() => {
  const role = currentUser.value?.role?.name || "";
  return ["super_admin", "security_admin", "security_analyst"].includes(role);
});

async function loadData() {
  isLoading.value = true;
  try {
    const res = await apiFetch("/api/findings");
    findings.value = Array.isArray(res?.findings) ? res.findings : [];
    if (res?.summary) {
      summary.value = res.summary;
    }
  } catch (err: any) {
    console.error("Gagal memuat temuan kerentanan:", err);
  } finally {
    isLoading.value = false;
  }
}

const uniqueProjects = computed(() => {
  const map = new Map<number, string>();
  findings.value.forEach((f) => {
    if (f.project)
      map.set(f.project.id, `${f.project.name} (${f.project.code})`);
  });
  return Array.from(map.entries()).map(([id, label]) => ({ id, label }));
});

const filteredFindings = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();
  return findings.value.filter((f) => {
    // Mode task: filter via pipeline tabs
    if (viewMode.value === "task") {
      if (taskStatusFilter.value === "open_pending") {
        if (f.status !== "open" && f.status !== "reviewing") return false;
      } else if (taskStatusFilter.value === "in_progress") {
        if (f.status !== "in_progress") return false;
      } else if (taskStatusFilter.value === "completed") {
        if (f.status !== "fixed" && f.status !== "resolved") return false;
      }
    } else {
      // Mode table SOC
      if (filterStatus.value !== "all" && f.status !== filterStatus.value)
        return false;
    }

    if (filterSeverity.value !== "all" && f.severity !== filterSeverity.value)
      return false;
    if (
      filterProject.value !== "all" &&
      String(f.project_id) !== filterProject.value
    )
      return false;

    if (!query) return true;
    const title = f.title?.toLowerCase() || "";
    const code = f.code?.toLowerCase() || "";
    const cve = f.cve?.toLowerCase() || "";
    const cwe = f.cwe?.toLowerCase() || "";
    const loc = (getLocationDisplay(f) || "").toLowerCase();
    const proj = (f.project?.name || "").toLowerCase();
    return (
      title.includes(query) ||
      code.includes(query) ||
      cve.includes(query) ||
      cwe.includes(query) ||
      loc.includes(query) ||
      proj.includes(query)
    );
  });
});

async function openFindingModal(
  finding: FindingItem,
  defaultTab: "details" | "ai" | "triage" = "details",
) {
  activeFinding.value = finding;
  modalTab.value = defaultTab;
  aiRemediationText.value = null;
  triageStatus.value = finding.status;
  triageNotes.value = finding.normalization_metadata?.triage_notes || "";
  triageMessage.value = null;
  isModalOpen.value = true;

  // Fetch full details if needed
  try {
    const res = await apiFetch(`/api/findings/${finding.id}`);
    if (res?.finding) {
      activeFinding.value = res.finding;
      triageStatus.value = res.finding.status;
      triageNotes.value =
        res.finding.normalization_metadata?.triage_notes || "";
    }
  } catch (err) {
    console.warn("Gagal memuat rincian lengkap finding:", err);
  }

  // If opening directly on AI tab, load guidance
  if (defaultTab === "ai") {
    fetchAiRemediation(finding.id);
  }
}

function closeModal() {
  isModalOpen.value = false;
  activeFinding.value = null;
  aiRemediationText.value = null;
  triageMessage.value = null;
}

async function fetchAiRemediation(findingId: number) {
  if (aiRemediationText.value) return;
  isLoadingAi.value = true;
  try {
    const res = await apiFetch(`/api/findings/${findingId}/ai-remediation`);
    aiRemediationText.value =
      res?.remediation?.guidance ||
      res?.remediation ||
      "Rekomendasi perbaikan AI berhasil digenerate.";
  } catch (err: any) {
    console.error("Gagal mengambil rekomendasi AI:", err);
    aiRemediationText.value =
      "Rekomendasi AI otomatis: Validasi input pengguna di sisi server, terapkan prepared statement/parameterized queries, dan lakukan escaping sebelum menampilkan data ke browser.";
  } finally {
    isLoadingAi.value = false;
  }
}

function switchModalTab(tab: "details" | "ai" | "triage") {
  modalTab.value = tab;
  if (tab === "ai" && activeFinding.value && !aiRemediationText.value) {
    fetchAiRemediation(activeFinding.value.id);
  }
}

async function submitTriage() {
  if (!activeFinding.value) return;
  isSavingTriage.value = true;
  triageMessage.value = null;

  try {
    const payload = {
      status: triageStatus.value,
      resolution_notes: triageNotes.value.trim() || null,
    };

    const res = await apiFetch(`/api/findings/${activeFinding.value.id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });

    activeFinding.value.status = res.finding.status;
    activeFinding.value.normalization_metadata =
      res.finding.normalization_metadata;

    // Update in list
    const idx = findings.value.findIndex(
      (f) => f.id === activeFinding.value?.id,
    );
    if (idx !== -1) {
      findings.value[idx].status = res.finding.status;
      findings.value[idx].normalization_metadata =
        res.finding.normalization_metadata;
    }

    triageMessage.value = {
      type: "success",
      text: "Status triage dan catatan resolusi berhasil diperbarui.",
    };
  } catch (err: any) {
    console.error("Gagal menyimpan triage:", err);
    triageMessage.value = {
      type: "danger",
      text:
        err?.data?.message ||
        err?.message ||
        "Gagal memperbarui status triage.",
    };
  } finally {
    isSavingTriage.value = false;
  }
}

function getSeverityBadge(sev: string) {
  switch (sev?.toLowerCase()) {
    case "critical":
      return "bg-danger text-danger-fg";
    case "high":
      return "bg-warning text-warning-fg";
    case "medium":
      return "bg-yellow text-yellow-fg";
    case "low":
      return "bg-info text-info-fg";
    case "informational":
      return "bg-secondary text-secondary-fg";
    default:
      return "bg-secondary text-secondary-fg";
  }
}

function getStatusBadge(status: string) {
  switch (status?.toLowerCase()) {
    case "open":
      return "bg-danger-lt text-danger";
    case "reviewing":
      return "bg-warning-lt text-warning";
    case "in_progress":
      return "bg-blue-lt text-blue";
    case "resolved":
    case "fixed":
      return "bg-success-lt text-success";
    case "false_positive":
    case "accepted":
      return "bg-secondary-lt text-secondary";
    default:
      return "bg-secondary-lt text-secondary";
  }
}

function getStatusLabel(status: string) {
  switch (status?.toLowerCase()) {
    case "open":
      return "Open (Terbuka)";
    case "reviewing":
      return "Sedang Ditinjau";
    case "in_progress":
      return "Dalam Perbaikan";
    case "resolved":
      return "Terselesaikan";
    case "fixed":
      return "Telah Diperbaiki";
    case "false_positive":
      return "False Positive";
    case "accepted":
      return "Risk Accepted";
    default:
      return status;
  }
}

function formatDate(dt?: string) {
  if (!dt) return "-";
  try {
    return new Date(dt).toLocaleString("id-ID", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return dt;
  }
}

onMounted(async () => {
  if (!currentUser.value) {
    await loadUser();
  }
  viewMode.value = canTriage.value ? "table" : "task";
  loadData();
});
</script>

<template>
  <div class="page-body mt-0">
    <div class="container-fluid">
      <!-- Page Header -->
      <div class="page-header d-print-none mb-3">
        <div class="row g-2 align-items-center justify-content-between">
          <div class="col">
            <div class="page-pretitle text-secondary">
              {{ viewMode === 'task' ? 'Tiket Tugas Rekayasa & Remediasi Celah' : 'Analisis Kerentanan & Remediasi' }}
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg
                v-if="viewMode === 'task'"
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-primary"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M9 11l3 3l8 -8" />
                <path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" />
              </svg>
              <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-danger"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M12 9v4" />
                <path d="M12 17h.01" />
                <path
                  d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"
                />
              </svg>
              <span>{{ viewMode === 'task' ? 'Tiket Perbaikan Celah (Issue Tracker)' : 'Temuan Kerentanan (Vulnerability Center & AI Remediation)' }}</span>
            </h2>
          </div>

          <!-- Mode Toggle (khusus admin/analis untuk beralih mode jika diinginkan) -->
          <div v-if="canTriage" class="col-auto">
            <div class="btn-group btn-group-sm">
              <button
                type="button"
                class="btn"
                :class="viewMode === 'task' ? 'btn-primary' : 'btn-secondary'"
                @click="viewMode = 'task'"
                title="Tampilan mode tiket perbaikan developer"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 11l3 3l8 -8" /><path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" /></svg>
                <span>Mode Tiket Developer</span>
              </button>
              <button
                type="button"
                class="btn"
                :class="viewMode === 'table' ? 'btn-primary' : 'btn-secondary'"
                @click="viewMode = 'table'"
                title="Tampilan tabel audit & triage SOC"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 5a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14z" /><path d="M3 10h18" /><path d="M10 3v18" /></svg>
                <span>Mode Tabel SOC</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Severity KPI Cards (hanya muncul di mode tabel SOC Admin) -->
      <div v-if="viewMode === 'table'" class="row row-cards mb-3">
        <!-- Total -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span
                    class="bg-primary-lt text-primary avatar avatar-sm rounded"
                  >
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                      <path
                        d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
                      />
                    </svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4">{{ summary.total }}</div>
                  <div class="text-secondary small">Total Temuan</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Critical -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span
                    class="bg-danger-lt text-danger avatar avatar-sm rounded"
                  >
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                      <path
                        d="M12 12c2 -2.96 0 -7 -1 -8c0 3.038 -1.773 4.741 -3 6c-1.226 1.26 -2 3.24 -2 5a6 6 0 1 0 12 0c0 -1.532 -1.056 -3.94 -2 -5c-1.786 3 -2.791 3 -4 2z"
                      />
                    </svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-danger">
                    {{ summary.critical }}
                  </div>
                  <div class="text-secondary small">Kritis (Critical)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- High -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span
                    class="bg-warning-lt text-warning avatar avatar-sm rounded"
                  >
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                      <path d="M12 9v4" />
                      <path
                        d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0"
                      />
                      <path d="M12 16h.01" />
                    </svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-warning">
                    {{ summary.high }}
                  </div>
                  <div class="text-secondary small">Tinggi (High)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Medium -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span
                    class="bg-yellow-lt text-yellow avatar avatar-sm rounded"
                  >
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                      <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                      <path d="M12 8v4" />
                      <path d="M12 16h.01" />
                    </svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-yellow">
                    {{ summary.medium }}
                  </div>
                  <div class="text-secondary small">Sedang (Medium)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Low -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-info-lt text-info avatar avatar-sm rounded">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                      <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                      <path d="M8 12l4 4l4 -4" />
                      <path d="M12 8v8" />
                    </svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-info">
                    {{ summary.low }}
                  </div>
                  <div class="text-secondary small">Rendah (Low)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Informational -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span
                    class="bg-secondary-lt text-secondary avatar avatar-sm rounded"
                  >
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                      <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                      <path d="M12 9h.01" />
                      <path d="M11 12h1v4h1" />
                    </svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-secondary">
                    {{ summary.informational }}
                  </div>
                  <div class="text-secondary small">Informasi</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TASK LIST / ISSUE TRACKER VIEW (Khusus Non-Admin / Developer atau saat mode Task aktif) -->
      <div v-if="viewMode === 'task'" class="task-tracker-wrapper mb-4">
        <!-- Pipeline Status Tabs & Sub-Filters -->
        <div class="card mb-3 border-0 shadow-sm">
          <div class="card-body py-2 px-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
              <!-- Status Pipeline Tabs -->
              <ul class="nav nav-pills gap-1 flex-wrap">
                <li class="nav-item">
                  <button
                    type="button"
                    class="nav-link py-1 px-3 d-flex align-items-center gap-2"
                    :class="{ active: taskStatusFilter === 'open_pending' }"
                    @click="taskStatusFilter = 'open_pending'"
                  >
                    <span class="status-indicator-dot bg-danger"></span>
                    <span>Perlu Diperbaiki</span>
                    <span class="badge" :class="taskStatusFilter === 'open_pending' ? 'bg-danger text-white' : 'bg-danger-lt text-danger'">
                      {{ taskCounts.open_pending }}
                    </span>
                  </button>
                </li>
                <li class="nav-item">
                  <button
                    type="button"
                    class="nav-link py-1 px-3 d-flex align-items-center gap-2"
                    :class="{ active: taskStatusFilter === 'in_progress' }"
                    @click="taskStatusFilter = 'in_progress'"
                  >
                    <span class="status-indicator-dot bg-warning"></span>
                    <span>Sedang Dikerjakan</span>
                    <span class="badge" :class="taskStatusFilter === 'in_progress' ? 'bg-warning text-white' : 'bg-warning-lt text-warning'">
                      {{ taskCounts.in_progress }}
                    </span>
                  </button>
                </li>
                <li class="nav-item">
                  <button
                    type="button"
                    class="nav-link py-1 px-3 d-flex align-items-center gap-2"
                    :class="{ active: taskStatusFilter === 'completed' }"
                    @click="taskStatusFilter = 'completed'"
                  >
                    <span class="status-indicator-dot bg-success"></span>
                    <span>Sudah Diperbaiki</span>
                    <span class="badge" :class="taskStatusFilter === 'completed' ? 'bg-success text-white' : 'bg-success-lt text-success'">
                      {{ taskCounts.completed }}
                    </span>
                  </button>
                </li>
                <li class="nav-item">
                  <button
                    type="button"
                    class="nav-link py-1 px-3 d-flex align-items-center gap-2"
                    :class="{ active: taskStatusFilter === 'all' }"
                    @click="taskStatusFilter = 'all'"
                  >
                    <span>Semua Tiket</span>
                    <span class="badge" :class="taskStatusFilter === 'all' ? 'bg-secondary text-white' : 'bg-secondary-lt text-secondary'">
                      {{ taskCounts.all }}
                    </span>
                  </button>
                </li>
              </ul>

              <!-- Secondary Filters: Severity, Project, Search -->
              <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2 ms-auto">
                <!-- Filter Severity -->
                <div class="filter-select-wrapper position-relative">
                  <span class="select-prefix-icon text-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>
                  </span>
                  <select
                    v-model="filterSeverity"
                    class="form-select form-select-sm custom-filter-select filter-severity-select"
                    :class="{ 'filter-active': filterSeverity !== 'all' }"
                    title="Filter Severity"
                  >
                    <option value="all">Semua Severity</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                    <option value="informational">Informational</option>
                  </select>
                </div>

                <!-- Filter Project -->
                <div class="filter-select-wrapper position-relative">
                  <span class="select-prefix-icon text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
                  </span>
                  <select
                    v-model="filterProject"
                    class="form-select form-select-sm custom-filter-select filter-project-select"
                    :class="{ 'filter-active': filterProject !== 'all' }"
                    title="Filter Proyek"
                  >
                    <option value="all">Semua Proyek</option>
                    <option v-for="p in uniqueProjects" :key="p.id" :value="String(p.id)">
                      {{ p.label }}
                    </option>
                  </select>
                </div>

                <!-- Search -->
                <div class="search-box-wrapper position-relative">
                  <div class="input-icon">
                    <span class="input-icon-addon text-primary">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    </span>
                    <input
                      v-model="searchQuery"
                      type="text"
                      class="form-control form-control-sm modern-search-input"
                      placeholder="Cari celah, file kode, CVE..."
                      @keydown.esc="searchQuery = ''"
                    />
                    <button
                      v-if="searchQuery"
                      type="button"
                      class="btn btn-sm btn-link p-0 text-muted search-clear-btn"
                      @click="searchQuery = ''"
                      title="Hapus pencarian (Esc)"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                    </button>
                  </div>
                </div>

                <!-- Reset -->
                <button
                  v-if="searchQuery || filterSeverity !== 'all' || filterProject !== 'all'"
                  type="button"
                  class="btn btn-sm btn-secondary d-flex align-items-center gap-1 filter-reset-btn"
                  @click="resetFilters"
                  title="Reset filter"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                  <span>Reset</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Issue Cards List Area -->
        <div v-if="isLoading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="text-secondary mt-2">Memuat tiket perbaikan celah...</div>
        </div>

        <!-- Empty State -->
        <div v-else-if="filteredFindings.length === 0" class="card empty-state-card py-5 text-center">
          <div class="card-body">
            <div class="empty-icon mb-3">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-success" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
            </div>
            <h3 class="empty-title">Tidak Ada Tiket Celah</h3>
            <p class="empty-subtitle text-secondary max-w-sm mx-auto">
              {{ taskStatusFilter === 'open_pending' ? 'Hebat! Seluruh celah keamanan pada kategori ini telah selesai atau belum ditemukan. Proyek Anda dalam status aman.' : 'Tidak ada tiket temuan yang cocok dengan filter yang dipilih saat ini.' }}
            </p>
            <div class="empty-action mt-3">
              <router-link to="/scan-mandiri" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                <span>Jalankan Scan Mandiri</span>
              </router-link>
            </div>
          </div>
        </div>

        <!-- Issue Cards List -->
        <div v-else class="task-items-list d-flex flex-column gap-2">
          <div
            v-for="f in filteredFindings"
            :key="f.id"
            class="card task-issue-card shadow-sm"
            :class="{
              'border-start-danger': f.severity === 'critical',
              'border-start-warning': f.severity === 'high',
              'border-start-yellow': f.severity === 'medium',
              'border-start-info': f.severity === 'low'
            }"
          >
            <div class="card-body p-3">
              <div class="row align-items-center g-3">
                <!-- Severity & Status Badge -->
                <div class="col-auto d-flex flex-column align-items-start gap-1" style="min-width: 95px;">
                  <span class="badge text-uppercase font-weight-bold" :class="getSeverityBadge(f.severity)">
                    {{ f.severity }}
                  </span>
                  <span class="badge badge-sm mt-1" :class="getStatusBadge(f.status)">
                    {{ getStatusLabel(f.status) }}
                  </span>
                </div>

                <!-- Middle: Title, Code Location & Metadata -->
                <div class="col">
                  <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                    <span class="badge bg-dark-lt text-secondary font-monospace">{{ f.code }}</span>
                    <span class="task-issue-title font-weight-semibold fs-3">
                      {{ f.title }}
                    </span>
                  </div>

                  <!-- Location Snippet (File Path & Line Number) -->
                  <div v-if="getLocationDisplay(f)" class="d-flex align-items-center gap-2 my-1">
                    <div class="code-location-badge d-inline-flex align-items-center gap-1 font-monospace">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-secondary" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                      <span class="file-path-text">{{ getLocationDisplay(f) }}</span>
                    </div>
                    <button
                      type="button"
                      class="btn btn-sm btn-icon btn-ghost-secondary copy-btn"
                      :title="copiedPathId === f.id ? 'Tersalin!' : 'Salin path file'"
                      @click="copyFilePath(getLocationDisplay(f) || undefined, f.id)"
                    >
                      <svg v-if="copiedPathId === f.id" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-success" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-muted" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                    </button>
                  </div>

                  <!-- Metadata Tags -->
                  <div class="d-flex align-items-center gap-2 flex-wrap text-muted small mt-1">
                    <span v-if="f.project" class="d-inline-flex align-items-center gap-1 font-weight-medium">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-primary" width="13" height="13" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
                      <span>{{ f.project.name }}</span>
                    </span>
                    <span v-if="f.cwe" class="badge bg-secondary-lt text-secondary font-monospace">{{ f.cwe }}</span>
                    <span v-if="f.cve" class="badge bg-danger-lt text-danger font-monospace">{{ f.cve }}</span>
                    <span v-if="f.scan_run?.engine_key" class="badge bg-purple-lt text-purple font-monospace">{{ f.scan_run.engine_key }}</span>
                    <span class="text-secondary opacity-75">Ditemukan: {{ formatDate(f.created_at) }}</span>
                  </div>
                </div>

                <!-- Right Action Buttons -->
                <div class="col-auto d-flex align-items-center gap-2 flex-wrap">
                  <button
                    type="button"
                    class="btn btn-sm btn-primary d-flex align-items-center gap-1"
                    @click="openFindingModal(f, 'ai')"
                    title="Buka panduan perbaikan kode & rekomendasi AI"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5" /></svg>
                    <span>Panduan Fix</span>
                  </button>

                  <button
                    v-if="f.status !== 'fixed' && f.status !== 'resolved'"
                    type="button"
                    class="btn btn-sm btn-success d-flex align-items-center gap-1"
                    @click="openMarkFixedModal(f)"
                    title="Tandai celah ini sudah diperbaiki atau sedang dikerjakan"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                    <span>Tandai Selesai</span>
                  </button>

                  <button
                    v-else
                    type="button"
                    class="btn btn-sm btn-secondary d-flex align-items-center gap-1"
                    @click="openFindingModal(f, 'details')"
                    title="Lihat rincian lengkap"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                    <span>Detail</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Table Card (Khusus mode tabel SOC Admin) -->
      <div v-if="viewMode === 'table'" class="card">
        <div
          class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-2"
        >
          <h3 class="card-title m-0 d-flex align-items-center gap-2">
            <span>Daftar Celah Keamanan</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{
              filteredFindings.length
            }}</span>
          </h3>

          <!-- Filter Toolbar -->
          <div
            class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2"
          >
            <!-- Filter Severity -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-danger">
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon icon-xs"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M12 9v4" />
                  <path
                    d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"
                  />
                  <path d="M12 16h.01" />
                </svg>
              </span>
              <select
                v-model="filterSeverity"
                class="form-select form-select-sm custom-filter-select filter-severity-select"
                :class="{ 'filter-active': filterSeverity !== 'all' }"
                title="Filter berdasarkan Severity"
              >
                <option value="all">Semua Severity</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
                <option value="informational">Informational</option>
              </select>
            </div>

            <!-- Filter Status -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-teal">
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon icon-xs"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                  <path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                </svg>
              </span>
              <select
                v-model="filterStatus"
                class="form-select form-select-sm custom-filter-select filter-status-select"
                :class="{ 'filter-active': filterStatus !== 'all' }"
                title="Filter Status Penanganan"
              >
                <option value="all">Semua Status</option>
                <option value="open">Open (Terbuka)</option>
                <option value="reviewing">Sedang Ditinjau</option>
                <option value="in_progress">Dalam Perbaikan</option>
                <option value="resolved">Terselesaikan (Resolved)</option>
                <option value="fixed">Telah Diperbaiki</option>
                <option value="false_positive">False Positive</option>
                <option value="accepted">Risk Accepted</option>
              </select>
            </div>

            <!-- Filter Project -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-primary">
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon icon-xs"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path
                    d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2"
                  />
                </svg>
              </span>
              <select
                v-model="filterProject"
                class="form-select form-select-sm custom-filter-select filter-project-select"
                :class="{ 'filter-active': filterProject !== 'all' }"
                title="Filter Proyek"
              >
                <option value="all">Semua Proyek</option>
                <option
                  v-for="p in uniqueProjects"
                  :key="p.id"
                  :value="String(p.id)"
                >
                  {{ p.label }}
                </option>
              </select>
            </div>

            <!-- Search Box -->
            <div class="search-box-wrapper position-relative">
              <div class="input-icon">
                <span class="input-icon-addon text-primary">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="icon icon-xs"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" />
                    <path d="M21 21l-6 -6" />
                  </svg>
                </span>
                <input
                  v-model="searchQuery"
                  type="text"
                  class="form-control form-control-sm modern-search-input"
                  placeholder="Cari judul celah, CVE, file..."
                  @keydown.esc="searchQuery = ''"
                />
                <button
                  v-if="searchQuery"
                  type="button"
                  class="btn btn-sm btn-link p-0 text-muted search-clear-btn"
                  @click="searchQuery = ''"
                  title="Hapus pencarian (Esc)"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="icon icon-xs"
                    width="14"
                    height="14"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M18 6l-12 12" />
                    <path d="M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Reset Button -->
            <button
              v-if="
                searchQuery ||
                filterSeverity !== 'all' ||
                filterStatus !== 'all' ||
                filterProject !== 'all'
              "
              type="button"
              class="btn btn-sm btn-secondary d-flex align-items-center gap-1 filter-reset-btn"
              @click="resetFilters"
              title="Reset seluruh filter"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="icon icon-xs"
                width="14"
                height="14"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
              </svg>
              <span>Reset Filter</span>
            </button>
          </div>
        </div>

        <!-- Table Loading State -->
        <div v-if="isLoading" class="card-body text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="text-secondary mt-2">Memuat temuan celah keamanan...</div>
        </div>

        <!-- Empty State -->
        <div
          v-else-if="filteredFindings.length === 0"
          class="card-body text-center py-5"
        >
          <div class="empty">
            <div class="empty-icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="icon icon-lg text-success"
                width="32"
                height="32"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path
                  d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
                />
                <path d="M9 12l2 2l4 -4" />
              </svg>
            </div>
            <p class="empty-title">Tidak ada celah keamanan ditemukan</p>
            <p class="empty-subtitle text-secondary">
              Tidak ada temuan kerentanan yang cocok dengan filter yang dipilih.
              Sistem aman!
            </p>
          </div>
        </div>

        <!-- Data Table (Desktop & Tablet >= 768px) -->
        <div v-else class="table-responsive d-none d-md-block">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th style="width: 110px">Severity</th>
                <th>Judul Kerentanan & Identifikasi</th>
                <th>Proyek & Scan Job</th>
                <th>Engine & Lokasi Celah</th>
                <th style="width: 110px">Status</th>
                <th style="width: 120px">Ditemukan</th>
                <th class="text-end" style="min-width: 230px">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in filteredFindings" :key="f.id">
                <td>
                  <span class="badge" :class="getSeverityBadge(f.severity)">
                    {{ f.severity.toUpperCase() }}
                  </span>
                </td>
                <td>
                  <div class="fw-bold text-wrap" style="max-width: 320px">
                    {{ f.title }}
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                    <span
                      class="badge bg-secondary-lt font-monospace text-muted"
                      style="font-size: 0.72rem"
                    >
                      {{ f.code }}
                    </span>
                    <span
                      v-if="f.cve"
                      class="badge bg-danger-lt font-monospace"
                      style="font-size: 0.72rem"
                    >
                      {{ f.cve }}
                    </span>
                    <span
                      v-if="f.cwe"
                      class="badge bg-blue-lt font-monospace"
                      style="font-size: 0.72rem"
                    >
                      {{ f.cwe }}
                    </span>
                  </div>
                </td>
                <td>
                  <div class="fw-medium">{{ f.project?.name || "-" }}</div>
                  <div class="text-secondary small font-monospace">
                    {{ f.scanJob?.code || f.scan_job?.code || "-" }}
                  </div>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-1">
                    <span
                      class="badge bg-indigo-lt text-uppercase font-monospace"
                      style="font-size: 0.75rem"
                    >
                      {{
                        f.scanRun?.engine_key ||
                        f.scan_run?.engine_key ||
                        "SECURITY-ENGINE"
                      }}
                    </span>
                  </div>
                  <code
                    class="text-muted small font-monospace d-block text-truncate mt-1"
                    style="max-width: 240px"
                    :title="f.location || ''"
                  >
                    {{ f.location || "-" }}
                  </code>
                </td>
                <td>
                  <span class="badge" :class="getStatusBadge(f.status)">
                    {{ getStatusLabel(f.status) }}
                  </span>
                </td>
                <td>
                  <div class="small text-secondary">
                    {{ formatDate(f.discovered_at) }}
                  </div>
                </td>
                <td class="text-end">
                  <div
                    class="d-inline-flex align-items-center justify-content-end gap-1"
                  >
                    <button
                      class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1"
                      @click="openFindingModal(f, 'details')"
                      title="Lihat Detail Temuan"
                    >
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="icon"
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                        fill="none"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      >
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                        <path
                          d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"
                        />
                      </svg>
                      <span>Detail</span>
                    </button>
                    <button
                      class="btn btn-sm btn-indigo d-inline-flex align-items-center gap-1"
                      @click="openFindingModal(f, 'ai')"
                      title="Solusi Remediasi AI"
                    >
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="icon"
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                        fill="none"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      >
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path
                          d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3"
                        />
                        <path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" />
                        <path d="M15 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                      </svg>
                      <span>AI</span>
                    </button>
                    <button
                      v-if="canTriage"
                      class="btn btn-sm btn-success d-inline-flex align-items-center gap-1"
                      @click="openFindingModal(f, 'triage')"
                      title="Triage & Update Status"
                    >
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="icon"
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                        fill="none"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      >
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M5 12l5 5l10 -10" />
                      </svg>
                      <span>Triage</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card List (< 768px) -->
        <div class="d-md-none list-group list-group-flush">
          <div
            v-for="f in filteredFindings"
            :key="f.id"
            class="list-group-item px-3 py-2 cursor-pointer"
            style="cursor: pointer"
            @click="openFindingModal(f, 'details')"
          >
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="badge" :class="getSeverityBadge(f.severity)">
                {{ f.severity.toUpperCase() }}
              </span>
              <span class="badge" :class="getStatusBadge(f.status)">
                {{ getStatusLabel(f.status) }}
              </span>
            </div>
            <div class="fw-bold mb-1">
              {{ f.title }}
            </div>
            <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
              <span
                class="badge bg-secondary-lt font-monospace text-muted"
                style="font-size: 0.7rem"
              >
                {{ f.code }}
              </span>
              <span
                v-if="f.cve"
                class="badge bg-danger-lt font-monospace"
                style="font-size: 0.7rem"
              >
                {{ f.cve }}
              </span>
              <span
                v-if="f.cwe"
                class="badge bg-blue-lt font-monospace"
                style="font-size: 0.7rem"
              >
                {{ f.cwe }}
              </span>
              <span
                class="badge bg-indigo-lt text-uppercase font-monospace ms-auto"
                style="font-size: 0.7rem"
              >
                {{
                  f.scanRun?.engine_key || f.scan_run?.engine_key || "ENGINE"
                }}
              </span>
            </div>
            <div
              class="d-flex align-items-center justify-content-between text-secondary small mt-1"
            >
              <span class="text-truncate" style="max-width: 180px">
                {{ f.project?.name || "-" }}
              </span>
              <span style="font-size: 0.75rem">{{
                formatDate(f.discovered_at)
              }}</span>
            </div>
            <div class="d-flex align-items-center gap-2 mt-2 pt-2 border-top">
              <button
                type="button"
                class="btn btn-sm btn-primary flex-fill"
                @click.stop="openFindingModal(f, 'details')"
              >
                Detail
              </button>
              <button
                type="button"
                class="btn btn-sm btn-indigo flex-fill d-inline-flex align-items-center justify-content-center gap-1"
                @click.stop="openFindingModal(f, 'ai')"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon icon-xs"
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path
                    d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3"
                  />
                  <path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" />
                  <path d="M15 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                </svg>
                <span>AI</span>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-secondary flex-fill"
                @click.stop="openFindingModal(f, 'triage')"
              >
                Triage
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Tandai Perbaikan (Quick Mark Fixed Modal) -->
    <div
      v-if="isMarkFixedModalOpen && targetFindingToFix"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.6); z-index: 1060;"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-success-lt py-3">
            <h5 class="modal-title d-flex align-items-center gap-2 text-success font-weight-bold">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
              <span>Perbarui Status Tiket Perbaikan</span>
            </h5>
            <button type="button" class="btn-close" @click="closeMarkFixedModal"></button>
          </div>
          <div class="modal-body p-3">
            <div class="mb-3">
              <div class="text-muted small mb-1">Tiket Masalah:</div>
              <div class="p-2 bg-body-secondary rounded">
                <div class="font-weight-semibold text-body">{{ targetFindingToFix.title }}</div>
                <div class="text-secondary small font-monospace mt-1">
                  {{ targetFindingToFix.code }} • {{ getLocationDisplay(targetFindingToFix) || 'Aset Proyek' }}
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label font-weight-medium required">Status Pengerjaan</label>
              <div class="form-selectgroup form-selectgroup-pills">
                <label class="form-selectgroup-item">
                  <input type="radio" v-model="fixStatusChoice" value="fixed" class="form-selectgroup-input" />
                  <span class="form-selectgroup-label d-flex align-items-center gap-1">
                    <span class="badge bg-success-lt me-1">✓</span> Telah Diperbaiki (Fixed)
                  </span>
                </label>
                <label class="form-selectgroup-item">
                  <input type="radio" v-model="fixStatusChoice" value="in_progress" class="form-selectgroup-input" />
                  <span class="form-selectgroup-label d-flex align-items-center gap-1">
                    <span class="badge bg-warning-lt me-1">⚡</span> Sedang Dikerjakan (In Progress)
                  </span>
                </label>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label font-weight-medium">Catatan Patch / PR / Commit (Opsional)</label>
              <textarea
                v-model="fixResolutionNotes"
                class="form-control"
                rows="3"
                placeholder="Contoh: Sudah diperbaiki di Pull Request #42 / commit 7a8b9c. Telah divalidasi dengan unit test."
              ></textarea>
              <small class="form-hint">Catatan ini akan tersimpan ke riwayat audit dan dapat dilihat oleh tim SOC.</small>
            </div>

            <div v-if="fixStatusError" class="alert alert-danger py-2 mb-0 mt-2">
              {{ fixStatusError }}
            </div>
          </div>
          <div class="modal-footer py-2">
            <button type="button" class="btn btn-secondary" @click="closeMarkFixedModal">Batal</button>
            <button
              type="button"
              class="btn btn-success d-flex align-items-center gap-1"
              :disabled="isSavingFixStatus"
              @click="submitMarkFixed"
            >
              <span v-if="isSavingFixStatus" class="spinner-border spinner-border-sm me-1" role="status"></span>
              <span>{{ isSavingFixStatus ? 'Menyimpan...' : 'Simpan Status' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Detail, AI Remediation & Triage -->
    <div
      v-if="isModalOpen && activeFinding"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5)"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div
            class="modal-header d-flex align-items-center justify-content-between"
          >
            <div>
              <div class="d-flex align-items-center gap-2 mb-1">
                <span
                  class="badge"
                  :class="getSeverityBadge(activeFinding.severity)"
                >
                  {{ activeFinding.severity.toUpperCase() }}
                </span>
                <span
                  class="badge"
                  :class="getStatusBadge(activeFinding.status)"
                >
                  {{ getStatusLabel(activeFinding.status) }}
                </span>
                <span class="badge bg-secondary-lt font-monospace">{{
                  activeFinding.code
                }}</span>
              </div>
              <h4 class="modal-title m-0 text-wrap">
                {{ activeFinding.title }}
              </h4>
            </div>
            <button
              type="button"
              class="btn-close"
              @click="closeModal"
            ></button>
          </div>

          <!-- Nav Tabs in Modal -->
          <div class="card-header p-0">
            <ul class="nav nav-tabs card-header-tabs m-0 px-3">
              <li class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: modalTab === 'details' }"
                  @click="switchModalTab('details')"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="icon icon-xs"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path
                      d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2"
                    />
                    <path
                      d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z"
                    />
                    <path d="M9 14l2 2l4 -4" />
                  </svg>
                  <span>Rincian & Bukti</span>
                </button>
              </li>
              <li class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: modalTab === 'ai' }"
                  @click="switchModalTab('ai')"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="icon icon-xs text-indigo"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path
                      d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3"
                    />
                    <path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" />
                    <path d="M15 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                  </svg>
                  <span>Solusi Remediasi AI</span>
                </button>
              </li>
              <li v-if="canTriage" class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: modalTab === 'triage' }"
                  @click="switchModalTab('triage')"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="icon icon-xs text-success"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path
                      d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
                    />
                    <path d="M9 12l2 2l4 -4" />
                  </svg>
                  <span>Triage & Status</span>
                </button>
              </li>
            </ul>
          </div>

          <div class="modal-body">
            <!-- TAB 1: DETAILS -->
            <div v-if="modalTab === 'details'">
              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <div class="small text-muted">Proyek:</div>
                  <div class="fw-bold">
                    {{ activeFinding.project?.name || "-" }} ({{
                      activeFinding.project?.code || "PRJ"
                    }})
                  </div>
                </div>
                <div class="col-sm-6">
                  <div class="small text-muted">Pekerjaan Scan:</div>
                  <div class="font-monospace">
                    {{
                      activeFinding.scanJob?.code ||
                      activeFinding.scan_job?.code ||
                      "-"
                    }}
                  </div>
                </div>
                <div class="col-12" v-if="activeFinding.location">
                  <div class="small text-muted">
                    Lokasi Celah / Path / Endpoint:
                  </div>
                  <code
                    class="text-primary font-monospace bg-body-secondary p-1 rounded d-block mt-1"
                  >
                    {{ activeFinding.location }}
                  </code>
                </div>
              </div>

              <!-- Deskripsi -->
              <div class="mb-3" v-if="activeFinding.description">
                <label class="form-label fw-bold text-secondary"
                  >Deskripsi Kerentanan</label
                >
                <div
                  class="p-3 bg-body-tertiary rounded text-wrap"
                  style="white-space: pre-wrap; font-size: 0.9rem"
                >
                  {{ activeFinding.description }}
                </div>
              </div>

              <!-- Solusi Bawaan -->
              <div class="mb-3" v-if="activeFinding.solution">
                <label class="form-label fw-bold text-success"
                  >Rekomendasi Penanganan Bawaan</label
                >
                <div
                  class="p-3 bg-body-tertiary rounded text-wrap"
                  style="white-space: pre-wrap; font-size: 0.9rem"
                >
                  {{ activeFinding.solution }}
                </div>
              </div>

              <!-- Evidence Snapshot -->
              <div v-if="activeFinding.evidence">
                <label class="form-label fw-bold text-secondary"
                  >Bukti Forensik (Evidence Payload)</label
                >
                <pre
                  class="bg-dark text-light p-3 rounded font-monospace small"
                  style="max-height: 200px; overflow-y: auto"
                  >{{ JSON.stringify(activeFinding.evidence, null, 2) }}</pre
                >
              </div>
            </div>

            <!-- TAB 2: AI REMEDIATION -->
            <div v-else-if="modalTab === 'ai'">
              <div class="card bg-indigo-lt border-0 mb-3">
                <div
                  class="card-body py-2 px-3 d-flex align-items-center gap-2"
                >
                  <span class="avatar avatar-xs bg-indigo text-indigo-fg">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon icon-xs"
                      width="14"
                      height="14"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                      <path
                        d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3"
                      />
                      <path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" />
                    </svg>
                  </span>
                  <div class="small">
                    <strong>TAMENG AI Remediation Copilot:</strong> Rekomendasi
                    perbaikan kontekstual berdasarkan tipe kelemahan dan standar
                    secure coding OWASP/NIST.
                  </div>
                </div>
              </div>

              <div v-if="isLoadingAi" class="text-center py-5">
                <div class="spinner-border text-indigo" role="status"></div>
                <div class="text-secondary mt-2">
                  Menghasilkan panduan perbaikan berbasis AI...
                </div>
              </div>

              <div
                v-else-if="aiRemediationText"
                class="bg-body-secondary p-3 rounded"
                style="max-height: 380px; overflow-y: auto"
              >
                <div
                  class="text-wrap"
                  style="
                    white-space: pre-wrap;
                    font-family: inherit;
                    font-size: 0.925rem;
                    line-height: 1.6;
                  "
                >
                  {{ aiRemediationText }}
                </div>
              </div>

              <div v-else class="text-center py-4 text-muted">
                Klik tombol di bawah untuk meminta AI menghasilkan solusi
                perbaikan khusus untuk temuan ini.
                <div class="mt-2">
                  <button
                    class="btn btn-indigo"
                    @click="fetchAiRemediation(activeFinding.id)"
                  >
                    Generate Rekomendasi AI
                  </button>
                </div>
              </div>
            </div>

            <!-- TAB 3: TRIAGE & RESOLUSI -->
            <div v-else-if="modalTab === 'triage'">
              <div
                v-if="triageMessage"
                class="alert alert-dismissible mb-3"
                :class="
                  triageMessage.type === 'success'
                    ? 'alert-success'
                    : 'alert-danger'
                "
              >
                {{ triageMessage.text }}
              </div>

              <form @submit.prevent="submitTriage">
                <div class="mb-3">
                  <label class="form-label required"
                    >Tentukan Status Penanganan</label
                  >
                  <select v-model="triageStatus" class="form-select" required>
                    <option value="open">Open (Celah Belum Ditangani)</option>
                    <option value="reviewing">
                      Reviewing (Sedang Ditinjau Tim Keamanan)
                    </option>
                    <option value="in_progress">
                      In Progress (Sedang Dikerjakan Developer)
                    </option>
                    <option value="resolved">
                      Resolved (Sudah Diselesaikan)
                    </option>
                    <option value="fixed">
                      Fixed (Telah Diperbaiki & Terverifikasi)
                    </option>
                    <option value="false_positive">
                      False Positive (Bukan Celah Nyata)
                    </option>
                    <option value="accepted">
                      Risk Accepted (Risiko Diterima dengan Catatan)
                    </option>
                  </select>
                </div>

                <div class="mb-3">
                  <label class="form-label">Catatan Triage & Resolusi</label>
                  <textarea
                    v-model="triageNotes"
                    class="form-control"
                    rows="4"
                    placeholder="Masukkan justifikasi, nomor tiket Jira/GitHub PR perbaikan, atau alasan penutupan..."
                  ></textarea>
                </div>

                <div class="d-flex justify-content-end">
                  <button
                    type="submit"
                    class="btn btn-success d-flex align-items-center gap-1"
                    :disabled="isSavingTriage"
                  >
                    <span
                      v-if="isSavingTriage"
                      class="spinner-border spinner-border-sm"
                      role="status"
                    ></span>
                    <span>{{
                      isSavingTriage ? "Menyimpan..." : "Perbarui Status Triage"
                    }}</span>
                  </button>
                </div>
              </form>
            </div>
          </div>

          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-secondary ms-auto"
              @click="closeModal"
            >
              Tutup
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.filter-toolbar-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.filter-select-wrapper {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.select-prefix-icon {
  position: absolute;
  left: 0.85rem;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  z-index: 5;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}

.custom-filter-select {
  height: 38px !important;
  padding-left: 2.45rem !important;
  padding-right: 2.25rem !important;
  border-radius: 9999px !important;
  font-size: 0.8125rem !important;
  font-weight: 500 !important;
  background-color: var(--tblr-bg-surface);
  border: 1px solid var(--tblr-border-color);
  color: var(--tblr-body-color);
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease,
    background-color 0.2s ease;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
  white-space: nowrap;
}

.custom-filter-select:hover {
  border-color: rgba(var(--tblr-primary-rgb), 0.5);
}

.custom-filter-select:focus {
  border-color: var(--tblr-primary);
  box-shadow: 0 0 0 3px rgba(var(--tblr-primary-rgb), 0.15);
  outline: none;
}

.custom-filter-select.filter-active {
  border-color: rgba(var(--tblr-primary-rgb), 0.8) !important;
  background-color: rgba(var(--tblr-primary-rgb), 0.08) !important;
  color: var(--tblr-primary) !important;
  font-weight: 600 !important;
}

/* Specific comfortable widths for filters */
.filter-severity-select {
  width: 175px;
  min-width: 175px;
}

.filter-status-select {
  width: 175px;
  min-width: 175px;
  max-width: 220px;
}

.filter-project-select {
  width: 185px;
  min-width: 185px;
  max-width: 240px;
}

.search-box-wrapper {
  position: relative;
}

.modern-search-input {
  height: 38px !important;
  border-radius: 9999px !important;
  padding-left: 2.45rem !important;
  padding-right: 2.25rem !important;
  font-size: 0.8125rem !important;
  width: 280px;
  min-width: 240px;
  background-color: var(--tblr-bg-surface);
  border: 1px solid var(--tblr-border-color);
  color: var(--tblr-body-color);
  transition: all 0.25s ease;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.modern-search-input:hover {
  border-color: rgba(var(--tblr-primary-rgb), 0.5);
}

.modern-search-input:focus {
  width: 330px;
  border-color: var(--tblr-primary);
  box-shadow: 0 0 0 3px rgba(var(--tblr-primary-rgb), 0.15);
  outline: none;
}

.search-clear-btn {
  position: absolute;
  right: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  z-index: 5;
  display: flex;
  align-items: center;
  justify-content: center;
}

.filter-reset-btn {
  height: 38px !important;
  border-radius: 9999px !important;
  font-size: 0.8125rem !important;
  padding: 0 1rem !important;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

@media (max-width: 767.98px) {
  .filter-toolbar-group {
    width: 100%;
  }
  .filter-select-wrapper,
  .search-box-wrapper,
  .filter-reset-btn {
    flex: 1 1 100%;
    width: 100%;
  }
  .filter-severity-select,
  .filter-status-select,
  .filter-project-select,
  .modern-search-input,
  .modern-search-input:focus {
    width: 100% !important;
    min-width: 100% !important;
    max-width: 100% !important;
  }
}
/* Issue Tracker Task List Styles */
.status-indicator-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
}

.task-issue-card {
  border-radius: 10px;
  border: 1px solid var(--tblr-border-color);
  background-color: var(--tblr-bg-surface);
  transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}

.task-issue-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
  border-color: rgba(var(--tblr-primary-rgb), 0.4);
}

.task-issue-card.border-start-danger {
  border-left: 4px solid var(--tblr-danger) !important;
}

.task-issue-card.border-start-warning {
  border-left: 4px solid var(--tblr-warning) !important;
}

.task-issue-card.border-start-yellow {
  border-left: 4px solid #f59f00 !important;
}

.task-issue-card.border-start-info {
  border-left: 4px solid var(--tblr-info) !important;
}

.task-issue-title {
  color: var(--tblr-body-color);
  line-height: 1.35;
}

.code-location-badge {
  font-size: 0.8125rem;
  background-color: var(--tblr-bg-surface-secondary);
  color: var(--tblr-body-color);
  padding: 3px 8px;
  border-radius: 6px;
  border: 1px solid var(--tblr-border-color);
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.copy-btn {
  width: 24px;
  height: 24px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  opacity: 0.7;
  transition: opacity 0.15s ease;
}

.copy-btn:hover {
  opacity: 1;
}

.empty-state-card {
  border-radius: 12px;
  border: 1px dashed var(--tblr-border-color);
  background: var(--tblr-bg-surface);
}
</style>
