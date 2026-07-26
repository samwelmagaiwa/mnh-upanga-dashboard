<script setup>
import { defineAsyncComponent, computed, onMounted, ref } from 'vue'
import { useDashboardStore } from '@/stores/dashboard'
import { useRouter } from 'vue-router'
import LoadingBanner from '@/components/LoadingBanner.vue'
import { ChartLine, ChartBar } from '../charts/index.js'
import { CIcon } from '@coreui/icons-vue'
import { CChart } from '@coreui/vue-chartjs'
import { 
  CDropdown, 
  CDropdownToggle, 
  CDropdownMenu, 
  CDropdownItem,
  CFormSelect
} from '@coreui/vue'

const dashboard = useDashboardStore()
const router = useRouter()
const hiddenDiseaseDepartments = ref([])

const showOfflineIndicator = computed(
  () => dashboard.remoteApiAvailable === false && dashboard.isUsingCachedData
)

const formatOfflineCountdown = computed(() => {
  const seconds = dashboard.offlineTimerCountdown || 0
  if (seconds <= 0) return ''
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}:${String(secs).padStart(2, '0')}`
})

const diseaseDepartmentColors = {
  'MLG-INTERNAL MEDICINE DEPARTMENT': '#3b82f6',
  'MLG-REHABILITATION MEDICINE DEPARTMENT': '#10b981',
  'MLG-ORTHOPAEDIC & TRAUMA DEPARTMENT': '#eab308',
  'MLG-OUTPATIENT DEPARTMENT': '#ef4444',
  'MLG - ORAL HEALTH DEPARTMENT': '#8b5cf6',
  'MLG-SURGERY DEPARTMENT': '#ec4899',
  'MLG-PSYCHIATRY & MENTAL HEALTH DEPARTMENT': '#06b6d4',
  'MLG-EMERGENCY MEDICINE DEPARTMENT': '#f97316',
  'MLG-PAEDIATRIC & CHILD HEALTH DEPARTMENT': '#6366f1',
  'MLG-ANESTHOLOGY & INTENSIVE CARE DEPARTMENT': '#14b8a6',
  'Other Departments': '#64748b',
}

const fallbackDiseaseDepartmentColors = [
  '#84cc16', '#f43f5e', '#a855f7', '#22c55e', '#0ea5e9', '#f59e0b', '#d946ef', '#94a3b8',
]

onMounted(() => {
  if (!dashboard.isAuthenticated) {
    router.push('/login')
    return
  }

  dashboard.fetchStats()
})

const handleClinicChange = (clinic) => {
  dashboard.selectedClinic = clinic
  dashboard.fetchTopDiseases()
}

const topDiseasesChartData = computed(() => {
  if (!dashboard.topDiseases || dashboard.topDiseases.length === 0) {
    return { labels: [], datasets: [] }
  }

  // Use the top 10 diseases
  const sortedDiseases = [...dashboard.topDiseases].sort((a, b) => b.total - a.total).slice(0, 10)
  const labels = sortedDiseases.map((d) => d.name)

  // Identify all unique departments and their total volume in these top 10 diseases
  const deptTotals = {}
  sortedDiseases.forEach((d) => {
    Object.entries(d.departments || {}).forEach(([name, count]) => {
      deptTotals[name] = (deptTotals[name] || 0) + count
    })
  })

  // To keep legend readable, keep top 10 departments and group others
  const sortedAllDepts = Object.entries(deptTotals)
    .sort((a, b) => b[1] - a[1])
  
  const topDeptNames = sortedAllDepts.slice(0, 12).map(c => c[0])
  const hasOthers = sortedAllDepts.length > 12

  const finalDeptNames = [...topDeptNames]
  if (hasOthers) finalDeptNames.push('Other Departments')

  const datasets = finalDeptNames.map((deptName, index) => {
    const hidden = hiddenDiseaseDepartments.value.includes(deptName)
    const color =
      diseaseDepartmentColors[deptName] ||
      fallbackDiseaseDepartmentColors[index % fallbackDiseaseDepartmentColors.length]

    return {
      label: deptName,
      data: sortedDiseases.map((d) => {
        if (deptName === 'Other Departments') {
          return Object.entries(d.departments || {})
            .filter(([name]) => !topDeptNames.includes(name))
            .reduce((sum, [_, count]) => sum + count, 0)
        }
        return (d.departments || {})[deptName] || 0
      }),
      backgroundColor: color,
      hoverBackgroundColor: color,
      hidden,
      barThickness: labels.length > 5 ? 24 : 32,
      borderRadius: 4, // Slight rounding for premium feel
    }
  })

  return { labels, datasets }
})

const top3Diseases = computed(() => {
  if (!dashboard.topDiseases) return []
  return [...dashboard.topDiseases]
    .sort((a, b) => b.total - a.total)
    .slice(0, 3)
})

const toggleDiseaseDepartment = (label) => {
  if (hiddenDiseaseDepartments.value.includes(label)) {
    hiddenDiseaseDepartments.value = hiddenDiseaseDepartments.value.filter((item) => item !== label)
    return
  }

  hiddenDiseaseDepartments.value = [...hiddenDiseaseDepartments.value, label]
}

const topDiseasesLabelPlugin = {
  id: 'topDiseasesLabelPlugin',
  afterDatasetsDraw(chart) {
    const { ctx, data } = chart
    const xScale = chart.scales.x

    if (!xScale || !data?.datasets?.length) return

    ctx.save()

    data.labels.forEach((_, index) => {
      let total = 0
      let lastVisibleElement = null

      data.datasets.forEach((dataset, datasetIndex) => {
        const value = Number(dataset.data[index] || 0)
        if (!value) return

        total += value

        const element = chart.getDatasetMeta(datasetIndex)?.data?.[index]
        if (!element) return
        lastVisibleElement = element

        const props = element.getProps(['x', 'base', 'y', 'height'], true)
        const width = Math.abs(props.x - props.base)

        if (width < 16) return

        const centerX = props.base + (props.x - props.base) / 2
        const centerY = props.y

        ctx.font = '700 10px Outfit, sans-serif'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.fillStyle = '#ffffff'
        ctx.strokeStyle = 'rgba(15, 23, 42, 0.35)'
        ctx.lineWidth = 2

        const text = value.toLocaleString()
        ctx.strokeText(text, centerX, centerY)
        ctx.fillText(text, centerX, centerY)
      })

      if (!lastVisibleElement || !total) return

      const endProps = lastVisibleElement.getProps(['x', 'y'], true)
      const totalText = total.toLocaleString()

      ctx.font = '900 12px Outfit, sans-serif'
      const textWidth = ctx.measureText(totalText).width
      const pillHeight = 24
      const pillWidth = textWidth + 16
      const x = Math.min(endProps.x + 10, xScale.right - pillWidth)
      const y = endProps.y - pillHeight / 2

      ctx.fillStyle = 'rgba(255, 255, 255, 0.96)'
      ctx.strokeStyle = 'rgba(148, 163, 184, 0.35)'
      ctx.lineWidth = 1
      ctx.beginPath()
      ctx.roundRect(x, y, pillWidth, pillHeight, 999)
      ctx.fill()
      ctx.stroke()

      ctx.fillStyle = '#0f172a'
      ctx.textAlign = 'center'
      ctx.textBaseline = 'middle'
      ctx.fillText(totalText, x + pillWidth / 2, endProps.y)
    })

    ctx.restore()
  },
}

const topDiseasesChartOptions = computed(() => ({
  indexAxis: 'y',
  maintainAspectRatio: false,
  interaction: {
    mode: 'index',
    intersect: false,
  },
  plugins: {
    legend: {
      display: false, // Switch to custom HTML legend for better placement
    },
    tooltip: {
      padding: 16,
      backgroundColor: 'rgba(15, 23, 42, 0.96)',
      titleFont: { size: 14, weight: 'bold' },
      bodyFont: { size: 13 },
      cornerRadius: 12,
      borderColor: 'rgba(255,255,255,0.1)',
      borderWidth: 1,
      displayColors: true,
      boxPadding: 8,
      filter: (tooltipItem) => tooltipItem.raw > 0,
      callbacks: {
        title: (tooltipItems) => {
          const diseaseName = tooltipItems[0].label
          const disease = dashboard.topDiseases.find((d) => d.name === diseaseName)
          return `${disease?.full_description || diseaseName}`
        },
        afterTitle: (tooltipItems) => {
          const diseaseName = tooltipItems[0].label
          const disease = dashboard.topDiseases.find((d) => d.name === diseaseName)
          return `Total Cases: ${disease?.total || 0}`
        },
        label: (context) => {
          const val = context.raw
          return `${context.dataset.label}: ${val.toLocaleString()}`
        },
      },
    },
  },
  scales: {
    x: {
      stacked: true,
      grace: '12%',
      grid: { display: true, drawBorder: false, color: 'rgba(148, 163, 184, 0.16)' },
      ticks: { 
        font: { size: 10, weight: '500' },
        color: '#64748b',
        callback: (value) => value >= 1000 ? (value/1000) + 'k' : value
      },
    },
    y: {
      stacked: true,
      grid: { display: false },
      ticks: { 
        font: { size: 12, weight: 'bold' },
        color: '#334155',
        padding: 15,
        callback: (value, index) => {
          const label = topDiseasesChartData.value.labels[index] || ''
          const disease = [...(dashboard.topDiseases || [])]
            .sort((a, b) => b.total - a.total)
            .slice(0, 10)
            .find((item) => item.name === label)

          return disease ? `${label} (${disease.total.toLocaleString()})` : label
        },
      },
    },
  },
}))

const chartOptions = {
  maintainAspectRatio: false,
}

const cleanDescription = (desc) => {
  if (!desc) return ''
  // Remove redundant repeating phrases often found in ICD data (e.g. "Phrase... Phrase")
  const parts = desc.split('...')
  if (parts.length > 1 && parts[0].trim() === parts[1].trim()) {
    return parts[0].trim()
  }
  // Trim common suffixes or repetitive comma parts
  const commaParts = desc.split(',')
  if (commaParts.length > 2 && commaParts[0].trim() === commaParts[1].trim()) {
    return commaParts.slice(1).join(',').trim()
  }
  return desc
}

// Helper functions to get values from dashboard metrics
const getValue = (title) => {
  return dashboard.metrics.find((m) => m.title === title)?.value || '0'
}

const getPercentage = (valueTitle, totalTitle) => {
  const valStr = dashboard.metrics.find((m) => m.title === valueTitle)?.value || '0'
  const totalStr = dashboard.metrics.find((m) => m.title === totalTitle)?.value || '1'

  const val = parseInt(valStr.replace(/,/g, ''))
  const total = parseInt(totalStr.replace(/,/g, ''))

  if (!total) return '0%'
  return Math.round((val / total) * 100) + '%'
}
</script>

<template>
  <div class="admin-dashboard px-1 px-lg-2 py-3" style="position: relative; min-height: 400px">
    <LoadingBanner v-if="dashboard.isLoading" />

    <div :style="{ opacity: dashboard.isLoading ? 0.6 : 1, transition: 'opacity 0.3s' }">
      <!-- Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 class="mb-1 fw-bold text-dark">Administrative Dashboard</h2>
          <p class="text-muted mb-0">Hospital Management Overview</p>
        </div>
        <div class="d-flex align-items-center gap-3">
          <CDropdown variant="btn-group">
            <CDropdownToggle color="white" class="premium-shadow-sm border-0 px-3">
              <span class="text-muted small me-2">Clinic:</span>
              <span class="fw-bold">{{ dashboard.selectedClinic }}</span>
            </CDropdownToggle>
            <CDropdownMenu style="max-height: 300px; overflow-y: auto">
              <CDropdownItem @click="handleClinicChange('All Clinics')">All Clinics</CDropdownItem>
              <CDropdownItem
                v-for="clinic in dashboard.realClinics"
                :key="clinic.clinic_code"
                @click="handleClinicChange(clinic.clinic_name)"
              >
                {{ clinic.clinic_name }}
              </CDropdownItem>
            </CDropdownMenu>
          </CDropdown>

          <div class="badge bg-primary-gradient px-3 py-2" style="font-size: 0.9rem">
            <span class="fw-bold">Admin</span> Access
          </div>
        </div>
      </div>

      <!-- Hospital Metrics Cards (Moved from Public Dashboard) -->
      <CRow :xs="{ gutter: 4 }" class="mb-4">
        <!-- Beds Card -->
        <CCol :md="4">
          <div class="stat-card premium-shadow">
            <div class="stat-card-header">
              <div class="stat-icon-wrapper bg-blue-soft">
                <CIcon icon="cil-bed" class="stat-icon text-blue" />
              </div>
              <div class="stat-main-info">
                <h3 class="stat-value text-blue">{{ getValue('Total Beds') }}</h3>
                <span class="stat-label">Total Beds</span>
              </div>
            </div>
            <div class="stat-card-footer">
              <div class="stat-footer-item">
                <span class="footer-label">Occupied</span>
                <div class="footer-value-group">
                  <span class="footer-value text-success">{{ getValue('Occupied Beds') }}</span>
                  <span class="stat-percentage text-success">{{
                    getPercentage('Occupied Beds', 'Total Beds')
                  }}</span>
                </div>
              </div>
              <div class="vr"></div>
              <div class="stat-footer-item">
                <span class="footer-label">Free</span>
                <div class="footer-value-group">
                  <span class="footer-value text-muted">{{ getValue('Free Beds') }}</span>
                  <span class="stat-percentage text-muted">{{
                    getPercentage('Free Beds', 'Total Beds')
                  }}</span>
                </div>
              </div>
            </div>
          </div>
        </CCol>

        <!-- Discharges Card -->
        <CCol :md="4">
          <div class="stat-card premium-shadow">
            <div class="stat-card-header">
              <div class="stat-icon-wrapper bg-purple-soft">
                <CIcon icon="cil-user" class="stat-icon text-purple" />
              </div>
              <div class="stat-main-info">
                <h3 class="stat-value text-purple">{{ getValue('Discharges') }}</h3>
                <span class="stat-label">Total Discharges</span>
              </div>
            </div>
            <div class="stat-card-footer">
              <div class="stat-footer-item">
                <span class="footer-label">Live</span>
                <div class="footer-value-group">
                  <span class="footer-value text-success">{{ getValue('LIVE') }}</span>
                  <span class="stat-percentage text-success">{{
                    getPercentage('LIVE', 'Discharges')
                  }}</span>
                </div>
              </div>
              <div class="vr"></div>
              <div class="stat-footer-item">
                <span class="footer-label">Dead</span>
                <div class="footer-value-group">
                  <span class="footer-value text-danger">{{ getValue('DEAD') }}</span>
                  <span class="stat-percentage text-danger">{{
                    getPercentage('DEAD', 'Discharges')
                  }}</span>
                </div>
              </div>
            </div>
          </div>
        </CCol>

        <!-- Attendance Card -->
        <CCol :md="4">
          <div class="stat-card premium-shadow">
            <div class="stat-card-header">
              <div class="stat-icon-wrapper bg-orange-soft">
                <CIcon icon="cil-clock" class="stat-icon text-orange" />
              </div>
              <div class="stat-main-info">
                <h3 class="stat-value text-orange">{{ getValue('Attendance') }}</h3>
                <span class="stat-label">Total Attendance</span>
              </div>
            </div>
            <div class="stat-card-footer">
              <div class="stat-footer-item">
                <span class="footer-label">On-Time</span>
                <div class="footer-value-group">
                  <span class="footer-value text-success">{{ getValue('ON-TIME') }}</span>
                  <span class="stat-percentage text-success">{{
                    getPercentage('ON-TIME', 'Attendance')
                  }}</span>
                </div>
              </div>
              <div class="vr"></div>
              <div class="stat-footer-item">
                <span class="footer-label">Late</span>
                <div class="footer-value-group">
                  <span class="footer-value text-warning">{{ getValue('LATE') }}</span>
                  <span class="stat-percentage text-warning">{{
                    getPercentage('LATE', 'Attendance')
                  }}</span>
                </div>
              </div>
            </div>
          </div>
        </CCol>
      </CRow>

      <!-- Glassmorphic Diseases Snapshot -->
      <div class="diseases-snapshot-container premium-shadow mb-4 overflow-hidden rounded-4">
        <div class="p-4">
          <div class="mb-4 d-flex flex-column gap-3">
            <h2 class="diseases-title fw-bold mb-0">Top 10 leading Cases by Department</h2>

            <div v-if="topDiseasesChartData.datasets.length > 0" class="d-flex flex-wrap gap-3 align-items-center">
              <button
                v-for="ds in topDiseasesChartData.datasets"
                :key="ds.label"
                type="button"
                class="legend-toggle-chip d-flex align-items-center gap-2"
                :class="{ inactive: hiddenDiseaseDepartments.includes(ds.label) }"
                @click="toggleDiseaseDepartment(ds.label)"
              >
                <span class="legend-dot" :style="{ backgroundColor: ds.backgroundColor }"></span>
                <span class="legend-chip-text fw-bold" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px">
                  {{ ds.label }}
                </span>
              </button>
            </div>
          </div>

          <CRow v-if="dashboard.topDiseases === null" class="py-5 text-center">
            <CCol>
              <div class="spinner-border text-primary mb-3" role="status" style="width: 2.5rem; height: 2.5rem"></div>
              <p class="text-muted small mb-0">Loading clinical data...</p>
            </CCol>
          </CRow>

          <CRow v-else-if="dashboard.topDiseases.length === 0" class="py-5 text-center">
            <CCol>
              <CIcon icon="cil-chart" size="xl" class="text-muted opacity-25 mb-2" />
              <p class="text-muted small">No clinical data found for this period.</p>
            </CCol>
          </CRow>

          <CRow v-else class="g-4">
            <!-- Main Chart Area -->
            <CCol :lg="9" class="position-relative">
              <div class="p-3 rounded-4 diseases-chart-card h-100" style="min-height: 420px">
                <CChart
                  type="bar"
                  :data="topDiseasesChartData"
                  :options="topDiseasesChartOptions"
                  :plugins="[topDiseasesLabelPlugin]"
                  style="height: 100%; width: 100%"
                />
              </div>
            </CCol>

            <!-- Top 3 Circular List Area -->
            <CCol :lg="3">
              <div class="p-3 rounded-4 diseases-chart-card h-100 d-flex flex-column">
                <h6 class="diseases-panel-title small fw-bold text-uppercase letter-spacing-1 mb-4">Top 3 Leading Cases</h6>
                <div class="flex-grow-1 overflow-auto pe-1">
                  <div v-for="(disease, idx) in top3Diseases" :key="disease.code" class="mb-3 glass-item premium-shadow-sm">
                    <div class="d-flex align-items-center gap-3">
                      <div class="circular-index">{{ idx + 1 }}</div>
                      <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                          <span class="diseases-name fw-bold h5 mb-0">{{ disease.name }}</span>
                          <span class="diseases-total fw-800 h4 mb-0">{{ disease.total.toLocaleString() }}</span>
                        </div>
                        <p class="diseases-desc small mb-0 truncate-2-lines line-height-sm">
                          {{ cleanDescription(disease.full_description) }}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="mt-3 pt-3 border-top border-dark border-opacity-10 text-center">
                  <span class="text-muted small italic">Analysis based on current filters</span>
                </div>
              </div>
            </CCol>
          </CRow>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-dashboard {
  background: #f8f9fa;
  min-height: 100vh;
}

.footer-value-group {
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
}

.stat-percentage {
  font-size: 0.85rem;
  font-weight: 600;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  background-color: rgba(0, 0, 0, 0.05);
}

.text-muted.stat-percentage {
  background-color: rgba(108, 117, 125, 0.15);
}

.bg-primary-gradient {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.premium-shadow {
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04);
  border-radius: 16px;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.premium-shadow:hover {
  transform: translateY(-5px);
  box-shadow: 0 15px 50px rgba(0, 0, 0, 0.07);
}

.premium-shadow-sm {
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
  border-radius: 10px;
}

.stat-card {
  background: white;
  border-radius: 16px;
  padding: 1.5rem;
  height: 100%;
  display: flex;
  flex-direction: column;
}

.stat-card-header {
  display: flex;
  align-items: flex-start;
  gap: 1.25rem;
  margin-bottom: 1.5rem;
}

.stat-icon-wrapper {
  width: 54px;
  height: 54px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.stat-icon {
  width: 28px;
  height: 28px;
}

.stat-main-info {
  display: flex;
  flex-direction: column;
}

.stat-value {
  font-size: 1.75rem;
  line-height: 1.2;
  font-weight: 800;
  margin-bottom: 0.25rem;
}

.stat-label {
  color: #64748b;
  font-size: 0.95rem;
  font-weight: 500;
}

.stat-card-footer {
  margin-top: auto;
  padding-top: 1.25rem;
  border-top: 1px dashed #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.stat-footer-item {
  flex: 1;
}

.footer-label {
  display: block;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
  margin-bottom: 0.5rem;
  font-weight: 700;
}

.footer-value {
  font-size: 1.15rem;
  font-weight: 700;
}

/* Colors */
.bg-blue-soft { background-color: rgba(51, 153, 255, 0.1); }
.text-blue { color: #3399ff; }
.bg-purple-soft { background-color: rgba(158, 119, 237, 0.1); }
.text-purple { color: #9e77ed; }
.bg-orange-soft { background-color: rgba(247, 144, 9, 0.1); }
.text-orange { color: #f79009; }

.bg-primary-gradient {
  background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
}

.diseases-snapshot-container {
  background: #ffffff;
  border: 1px solid #e2e8f0;
}

.diseases-title {
  color: #3399ff;
}

.diseases-chart-card {
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  border: 1px solid #e2e8f0;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.45);
}

.glass-item {
  background: #ffffff;
  border-radius: 14px;
  padding: 1.25rem;
  border: 1px solid #e2e8f0;
  transition: transform 0.2s, background 0.2s;
}

.glass-item:hover {
  background: #f8fbff;
  transform: translateY(-2px);
}

.diseases-panel-title {
  color: #64748b;
}

.diseases-name {
  color: #0f172a;
}

.diseases-total {
  color: #3399ff;
}

.diseases-desc {
  color: #64748b;
}

.circular-index {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #fff;
  color: #0d122b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 0.9rem;
  flex-shrink: 0;
  box-shadow: 0 8px 18px rgba(51, 153, 255, 0.18);
}

.legend-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
  box-shadow: 0 0 10px rgba(255, 255, 255, 0.18);
}

.legend-toggle-chip {
  border: 1px solid #dbeafe;
  background: #f8fbff;
  border-radius: 999px;
  padding: 0.3rem 0.7rem;
  transition: all 0.2s ease;
}

.legend-toggle-chip:hover {
  background: #eef6ff;
  transform: translateY(-1px);
}

.legend-toggle-chip.inactive {
  opacity: 0.45;
  filter: grayscale(0.2);
}

.legend-chip-text {
  color: #475569;
}

.line-height-sm { line-height: 1.2; }

.italic { font-style: italic; }

.premium-hover-effect {
  transition: all 0.2s ease-in-out;
}
</style>
