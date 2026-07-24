<script setup>
import { computed, ref, watch, nextTick, onMounted } from 'vue'
import { CChartBar } from '@coreui/vue-chartjs'
import { useDashboardStore } from '@/stores/dashboard'
import { Chart, registerables } from 'chart.js'
import { CIcon } from '@coreui/icons-vue'
import { cilHospital, cilChart, cilBarChart } from '@coreui/icons'

Chart.register(...registerables)

const dashboard = useDashboardStore()

// Ref for chart scroll container
const chartScrollRef = ref(null)

// Breakdown toggle state
const breakdownEnabled = ref(false)

// Auto-scroll to show "Today" data on mount and when data changes
const scrollToToday = () => {
  // Try multiple times with increasing delays to ensure DOM is ready
  const tryScroll = (attempt) => {
    if (attempt > 3) return
    
    const container = chartScrollRef.value
    if (!container) {
      setTimeout(() => tryScroll(attempt + 1), 200 * attempt)
      return
    }
    
    const labels = normalizedTrendData.value?.labels || []
    const widthPerPoint = breakdownEnabled.value ? 250 : 220
    
    // Calculate position to show rightmost data (Today)
    // Use scrollWidth - clientWidth to scroll to max right
    const maxScroll = container.scrollWidth - container.clientWidth
    
    if (maxScroll > 0) {
      container.scrollLeft = maxScroll
      console.log('[Chart] Scroll attempt', attempt, '- maxScroll:', maxScroll)
    } else {
      // Retry if not ready
      setTimeout(() => tryScroll(attempt + 1), 200 * attempt)
    }
  }
  
  tryScroll(1)
}

const normalizeTrendLabel = (label) => String(label || '').toLowerCase().replace(/[^a-z0-9]/g, '')

const getAnchorDate = () => {
  switch (dashboard.selectedPeriod) {
    case 'day':
      return dashboard.selectedDay || new Date()
    case 'week': {
      const week = dashboard.selectedWeek
      return Array.isArray(week) && week[1] ? week[1] : week || new Date()
    }
    case 'month': {
      const monthValue = dashboard.selectedMonth
      const monthDate =
        monthValue && typeof monthValue === 'object' && 'month' in monthValue
          ? new Date(monthValue.year, monthValue.month + 1, 0)
          : new Date(new Date(monthValue || new Date()).getFullYear(), new Date(monthValue || new Date()).getMonth() + 1, 0)
      return monthDate
    }
    case 'year': {
      const yearValue = dashboard.selectedYear
      const year =
        yearValue && typeof yearValue === 'object' && 'year' in yearValue
          ? yearValue.year
          : new Date(yearValue || new Date()).getFullYear()
      return new Date(year, 11, 31)
    }
    case 'range': {
      const range = dashboard.selectedRange
      return Array.isArray(range) && range[1] ? range[1] : new Date()
    }
    default:
      return new Date()
  }
}

const formatTrendDayLabel = (date) => {
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const yesterday = new Date(today)
  yesterday.setDate(today.getDate() - 1)

  if (date.getTime() === today.getTime()) {
    return 'Today'
  }

  if (date.getTime() === yesterday.getTime()) {
    return 'Yesterday'
  }

  const weekday = date.toLocaleDateString('en-US', { weekday: 'short' })
  const day = String(date.getDate()).padStart(2, '0')
  return `${day} ${weekday}`
}

const rawTrendData = computed(() => dashboard.serviceTrendData || { labels: [], datasets: [] })

const normalizedTrendData = computed(() => {
  const rawData = rawTrendData.value

  if (!rawData || !rawData.datasets?.length || !rawData.labels?.length) {
    return rawData || { labels: [], datasets: [] }
  }

  const period = dashboard.selectedPeriod
  const isYearly = period === 'year'
  
  // Skip normalization for Yearly view (12 months) or breakdown mode
  if (isYearly || breakdownEnabled.value) {
    return rawData
  }

  // --- 1. DATA-DRIVEN MAPPING (Primary Strategy) ---
  // If backend provides dates array, use it directly to ensure perfect data alignment
  if (rawData.dates && rawData.dates.length > 0) {
    const freshLabels = rawData.dates.map((dateStr) => {
      // Support both Week keys (W1) and ISO dates (YYYY-MM-DD)
      if (dateStr.startsWith('W')) {
        return `Week ${dateStr.replace('W', '')}`
      }
      const [y, m, d] = dateStr.split('-')
      const date = new Date(parseInt(y), parseInt(m) - 1, parseInt(d))
      date.setHours(0, 0, 0, 0)
      return formatTrendDayLabel(date)
    })

    // Create a mapping of DateKey -> Index for reliable lookup
    const dateToIndexMap = new Map()
    rawData.dates.forEach((date, i) => dateToIndexMap.set(date, i))

    // Reconstruct the data array using the date keys to avoid index-shifting bugs
    return {
      ...rawData,
      labels: freshLabels,
      datasets: (rawData.datasets || []).map((ds, i) => ({
        ...ds,
        label: metricDetails.value[i]?.label || ds.label,
        data: rawData.dates.map((dateKey) => {
          const rawIdx = dateToIndexMap.get(dateKey)
          return ds.data?.[rawIdx] ?? 0
        })
      }))
    }
  }

  // --- 2. CALENDAR-BASED RECONSTRUCTION (Fallback/Normalization Strategy) ---
  const anchor = new Date(getAnchorDate())
  if (Number.isNaN(anchor.getTime())) return rawData
  anchor.setHours(0, 0, 0, 0)

  let rangeLength = 7
  if (period === 'month') {
    rangeLength = anchor.getDate() 
  } else if (period === 'range') {
    const range = dashboard.calculateDateRange()
    if (range.start_date) {
       const start = new Date(range.start_date)
       rangeLength = Math.max(1, Math.round((anchor - start) / (1000 * 60 * 60 * 24)) + 1)
       if (rangeLength > 45) return rawData 
    }
  }

  const targetDates = Array.from({ length: rangeLength }, (_, index) => {
    const d = new Date(anchor)
    d.setDate(anchor.getDate() - (rangeLength - 1 - index))
    return d
  })

  const targetLabels = targetDates.map((date) => formatTrendDayLabel(date))
  const labelIndexMap = new Map()
  
  if (rawData.dates && rawData.dates.length > 0) {
    rawData.dates.forEach((date, index) => labelIndexMap.set(date, index))
  } else {
    (rawData.labels || []).forEach((label, index) => 
      labelIndexMap.set(normalizeTrendLabel(label), index)
    )
  }

  return {
    ...rawData,
    labels: targetLabels,
    datasets: (rawData.datasets || []).map((dataset, i) => ({
      ...dataset,
      label: metricDetails.value[i]?.label || dataset.label,
      data: targetLabels.map((_, index) => {
        const date = targetDates[index]
        const y = date.getFullYear()
        const m = String(date.getMonth() + 1).padStart(2, '0')
        const d = String(date.getDate()).padStart(2, '0')
        
        const dateKey = `${y}-${m}-${d}`
        let rawIndex = labelIndexMap.get(dateKey)
        
        if (rawIndex === undefined) {
           const weekday = date.toLocaleDateString('en-US', { weekday: 'short' })
           rawIndex = labelIndexMap.get(`${d} ${weekday}`)
        }
        
        return rawIndex !== undefined ? (dataset.data?.[rawIndex] ?? 0) : 0
      }),
    })),
  }
})

// Watch for data changes and scroll to today
watch([() => normalizedTrendData.value, () => dashboard.selectedPeriod, () => breakdownEnabled.value], () => {
  scrollToToday()
}, { deep: true })

onMounted(() => {
  scrollToToday()
})

// Watch for toggle changes to refetch data
watch(breakdownEnabled, (newVal) => {
  dashboard.setBreakdownMode(newVal)
})

// Dynamic width calculations based on breakdown mode and data points
const MIN_CHART_WIDTH = 600

const chartWidth = computed(() => {
  const labels = normalizedTrendData.value?.labels || []
  const numLabels = labels.length
  const widthPerPoint = breakdownEnabled.value ? 250 : 220
  const calculatedWidth = numLabels * widthPerPoint

  if (breakdownEnabled.value && numLabels === 12) {
    return Math.max(calculatedWidth, 1600)
  }

  return Math.max(calculatedWidth, MIN_CHART_WIDTH)
})

const needsScroll = computed(() => {
  const labels = normalizedTrendData.value?.labels || []
  const threshold = 4
  return labels.length > threshold
})

const metricDetails = computed(() => [
  { id: 'trend', label: 'Overall Trend', color: '#1e293b' },
  { id: 'opd', label: 'Total OPD', color: '#3b82f6' },
  { id: 'emergency', label: 'Emergency', color: '#dc3545' },
  { id: 'consulted', label: 'Consulted', color: '#16a34a' },
  { id: 'not_consulted', label: dashboard.isTodaySelected ? 'Await Consultation' : 'Not Consulted', color: '#ec4899' },
  { id: 'new', label: 'New Visits', color: '#06b6d4' },
  { id: 'followup', label: 'Follow-ups', color: '#6610f2' },
])

const keyReferralMap = {
  '000037': 'SELF REFERRAL',
  '000035': 'OTHERS',
  '000002': 'AMANA HOSPITAL',
  '000023': 'MWANANYAMALA MUNICIPAL HOSPITAL',
  '000031': 'TEMEKE HOSPITAL',
  '000038': 'MNAZI MMOJA HOSPITAL - DAR',
  '000045': 'MUHIMBILI ORTHOPEDIC INSTITUTE',
  '000020': 'MOROGORO REGIONAL HOSPITAL',
  '000041': 'OCEAN ROAD HOSPITAL - DAR',
  '000033': 'TUMBI SPECIAL HOSPITAL',
  '000004': 'BAGAMOYO DISTRICT HOSPITAL',
  '000040': 'MNAZI MMOJA HOSPITAL - ZANZIBAR',
  '000024': 'SHINYANGA REGIONAL HOSPITAL',
  '000003': 'MANYARA REGIONAL HOSPITAL',
  '000010': 'KCMC REGIONAL HOSPITAL',
  '000005': 'BOMBO REGION HOSPITAL',
  '000013': 'LUGALO HOSPITAL',
  '000001': 'THE AGA KHAN HOSPITAL',
  '000009': 'IRINGA REGIONAL HOSPITAL',
  '000047': 'KILWA ROAD POLICE HOSPITAL',
  '000017': 'MBEYA REFERRAL HOSPITAL',
  '000026': 'SINGIDA REGIONAL HOSPITAL',
  '000042': 'CCBRT HOSPITAL - DAR',
  '000008': 'DODOMA REGIONAL HOSPITAL',
  '000011': 'KISARAWE HOSPITAL',
  '000007': 'BUKOBA REGIONAL HOSPITAL',
  '000039': 'MUHEZA DISTRICT HOSPITAL',
  '000012': 'LIGULA HOSPITAL',
}

const referralLoading = computed(() => !dashboard.isInitialized || dashboard.referralStats === null)

const referralData = computed(() => {
  const stats = dashboard.referralStats
  if (!stats || !Array.isArray(stats)) return []
  return stats.map((item) => {
    let name = item.name
    if (!name || /^\d+$/.test(name.trim()) || name.toLowerCase().includes('facility')) {
      name = keyReferralMap[item.code] || `REF. HOSPITAL (${item.code})`
    }
    return { ...item, name }
  })
})

const totalReferrals = computed(() => {
  return referralData.value.reduce((sum, item) => sum + (item.count || 0), 0)
})

const barLabelsPlugin = {
  id: 'barLabels',
  afterDatasetsDraw(chart) {
    const { ctx, scales } = chart
    const numLabels = chart.data.labels?.length || 1
    const isBreakdownMode = breakdownEnabled.value

    chart.data.datasets.forEach((dataset, datasetIndex) => {
      const meta = chart.getDatasetMeta(datasetIndex)
      const color = metricDetails.value[datasetIndex]?.color || '#333'

      meta.data.forEach((bar, index) => {
        const value = dataset.data[index]
        const { x, y } = bar.tooltipPosition()

        const displayValue =
          value === null || value === undefined || value === 0
            ? '0'
            : new Intl.NumberFormat('en-US').format(value)

        ctx.save()
        // SPECIAL HANDLING FOR TREND LINE (Index 0)
        // Skip labels for the line chart (Trend) to avoid redundancy and misalignment
        // Total OPD (dataset index 1) will now have its label drawn on top of its bar like other metrics
        if (datasetIndex === 0) return

        const yPos =
          value === null || value === undefined || value === 0 ? scales.y.bottom - 8 : y - 5

        const fontSize = isBreakdownMode ? (numLabels > 8 ? 13 : numLabels > 4 ? 14 : 16) : 18
        ctx.font = `bold ${fontSize}px 'Outfit', sans-serif`
        ctx.fillStyle = color
        ctx.textAlign = 'center'
        ctx.textBaseline = 'bottom'
        ctx.shadowColor = 'white'
        ctx.shadowBlur = 5
        ctx.fillText(displayValue, x, yPos + 2)

        ctx.restore()
      })
    })
  },
}

const chartData = computed(() => {
  const rawData = normalizedTrendData.value
  if (!rawData?.datasets) return rawData || { labels: [], datasets: [] }

  let barPercentage = 0.95
  let categoryPercentage = 0.8

  const labels = rawData.labels || []
  const highlightIndexes = new Set()
  labels.forEach((label, index) => {
    if (label === 'Today' || label === 'Yesterday') {
      highlightIndexes.add(index)
    }
  })

  return {
    ...rawData,
    datasets: rawData.datasets.map((ds, index) => {
      const color = metricDetails.value[index]?.color || ds.backgroundColor
      const isTrendDataset = index === 0 || ds.type === 'line'
      return {
        ...ds,
        backgroundColor: isTrendDataset
          ? color
          : labels.map((_, labelIndex) =>
              highlightIndexes.has(labelIndex) ? `${color}E6` : color,
            ),
        borderColor: isTrendDataset
          ? labels.map((_, labelIndex) =>
              highlightIndexes.has(labelIndex) ? color : `${color}CC`,
            )
          : labels.map((_, labelIndex) =>
              highlightIndexes.has(labelIndex) ? color : `${color}CC`,
            ),
        borderWidth: isTrendDataset
          ? labels.map((_, labelIndex) => (highlightIndexes.has(labelIndex) ? 4 : 3))
          : labels.map((_, labelIndex) => (highlightIndexes.has(labelIndex) ? 3 : 1.5)),
        borderRadius: breakdownEnabled.value ? 3 : 4,
        borderSkipped: false,
        barPercentage,
        categoryPercentage,
        pointRadius: isTrendDataset
          ? labels.map((_, labelIndex) => (highlightIndexes.has(labelIndex) ? 6 : 4))
          : ds.pointRadius,
        pointHoverRadius: isTrendDataset
          ? labels.map((_, labelIndex) => (highlightIndexes.has(labelIndex) ? 7 : 5))
          : ds.pointHoverRadius,
        pointBackgroundColor: isTrendDataset
          ? labels.map((_, labelIndex) => (highlightIndexes.has(labelIndex) ? '#ffffff' : color))
          : ds.pointBackgroundColor,
        pointBorderColor: isTrendDataset
          ? labels.map((_, labelIndex) => (highlightIndexes.has(labelIndex) ? color : '#ffffff'))
          : ds.pointBorderColor,
        pointBorderWidth: isTrendDataset
          ? labels.map((_, labelIndex) => (highlightIndexes.has(labelIndex) ? 3 : 2))
          : ds.pointBorderWidth,
      }
    }),
  }
})

const maxDataValue = computed(() => {
  let max = 0
  if (normalizedTrendData.value && normalizedTrendData.value.datasets) {
    normalizedTrendData.value.datasets.forEach((ds) => {
      const dMax = Math.max(...(Array.isArray(ds.data) ? ds.data : [0]))
      if (dMax > max) max = dMax
    })
  }

  const numLabels = normalizedTrendData.value?.labels?.length || 0
  if (!breakdownEnabled.value && dashboard.realStats && numLabels <= 1) {
    const rs = dashboard.realStats
    const cardValues = [
      rs.total_patients || 0,
      rs.emergency_patients || 0,
      rs.consulted || 0,
      rs.pending || 0,
      rs.new_visits || 0,
      rs.followups || 0,
    ]
    const cardMax = Math.max(...cardValues)
    if (cardMax > max) max = cardMax
  }
  return max > 0 ? Math.ceil(max * 1.1) : 10
})

const chartOptions = computed(() => {
  const numLabels = normalizedTrendData.value?.labels?.length || 1
  const isBreakdownMode = breakdownEnabled.value
  let xFontSize = 20
  let xRotation = 0

  if (numLabels > 1) {
    xRotation = 45
    if (numLabels > 10) {
      xFontSize = 12
    } else if (numLabels > 6) {
      xFontSize = 14
    } else {
      xFontSize = 15
    }
  }

  return {
    layout: {
      padding: { top: 40, bottom: isBreakdownMode ? 20 : 0, left: 10, right: 10 },
    },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(255, 255, 255, 0.95)',
        titleColor: '#1e293b',
        bodyColor: '#475569',
        borderColor: '#e2e8f0',
        borderWidth: 1,
        padding: 14,
        boxPadding: 6,
        usePointStyle: true,
        callbacks: {
          label: (context) => {
            let label = context.dataset.label || ''
            const value = context.raw || 0
            
            // Special handling for Await Consultation / Not Consulted dataset
            // Usually at index 4, but we check if the label starts with either
            if (label === 'Await Consultation' || label === 'Not Consulted') {
              const xLabel = context.label || ''
              // If the x-axis label is not 'Today', it should be 'Not Consulted'
              if (xLabel !== 'Today' && !xLabel.toLowerCase().includes('today')) {
                label = 'Not Consulted'
              } else {
                label = 'Await Consultation'
              }
            }
            
            return `${label}: ${value.toLocaleString()}`
          },
        },
      },
    },
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      x: {
        grid: { display: false },
        ticks: {
          color: '#1e293b',
          font: { size: xFontSize, weight: '700', family: "'Outfit', sans-serif" },
          maxRotation: xRotation,
          minRotation: xRotation,
          padding: 12,
          callback: function (value) {
            const label = this.getLabelForValue(value)
            return label === 'Today' || label === 'Yesterday' ? [label, ' '] : label
          },
        },
      },
      y: {
        beginAtZero: true,
        max: maxDataValue.value,
        title: isBreakdownMode
          ? {
              display: true,
              text: 'Patients',
              font: { size: 12, weight: '600' },
              color: '#64748b',
            }
          : { display: false },
        grid: {
          color: 'rgba(203, 213, 225, 0.4)',
          drawBorder: false,
        },
        ticks: {
          color: '#94a3b8',
          font: { size: 12, weight: '600' },
          maxTicksLimit: 10,
          callback: (value) => value.toLocaleString(),
        },
      },
    },
  }
})
</script>

<template>
  <CCard class="mt-0 mb-4 service-trends-card border-0 shadow-lg overflow-hidden">
    <div class="glass-header px-4 py-1">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h5 class="header-title mb-0 fw-bold text-primary" style="font-size: 16px">
            Service Trends Analysis
          </h5>
        </div>
        <div class="d-flex align-items-center gap-3">
          <div v-if="dashboard.remoteApiAvailable === false && dashboard.isOfflineUIReported" class="d-flex align-items-center gap-2 px-3 py-1 bg-amber-50 border border-amber-200 rounded-pill shadow-sm">
            <div class="spinner-grow spinner-grow-sm text-amber-500" role="status" style="width: 8px; height: 8px;"></div>
            <span class="fw-bold text-amber-700" style="font-size: 11px; letter-spacing: 0.5px">RECONNECTING... (CACHED)</span>
          </div>
          <div
            class="premium-stat-pill d-flex align-items-center shadow-sm border-primary-subtle bg-white"
            style="scale: 0.9; margin: -5px 0"
          >
            <span
              class="pill-label bg-primary text-white border-0"
              style="padding: 4px 8px; font-size: 11px"
              >Total Referrals</span
            >
            <span v-if="referralLoading" class="pill-value text-muted fs-6 px-3">...</span>
            <span v-else class="pill-value text-primary fs-6 px-3">{{
              totalReferrals.toLocaleString()
            }}</span>
          </div>
          <CBadge
            v-if="!referralLoading"
            color="primary"
            shape="rounded-pill"
            class="facilities-badge shadow-sm"
            style="font-size: 11px; padding: 4px 12px"
          >
            {{ referralData.length }} Facilities
          </CBadge>
        </div>
      </div>
    </div>

    <CCardBody class="p-0 border-0">
      <CRow class="m-0 mx-0">
        <CCol :xl="9" :lg="12" class="p-0 border-end bg-white">
          <div class="px-4 py-2 bg-light-subtle border-bottom">
            <div
              class="custom-legend d-flex flex-wrap align-items-center gap-3 mb-1 p-2 bg-white rounded-4 shadow-sm border"
            >
              <div
                v-for="metric in metricDetails"
                :key="metric.id"
                class="legend-item d-flex align-items-center gap-2"
              >
                <span
                  class="legend-dot"
                  :style="{ backgroundColor: metric.color, width: '12px', height: '12px' }"
                ></span>
                <span class="legend-label fw-bold" style="font-size: 14px; color: #334155">{{
                  metric.label
                }}</span>
              </div>

              <div class="ms-auto">
                <button
                  type="button"
                  class="breakdown-pill-btn d-flex align-items-center"
                  :class="{ active: breakdownEnabled }"
                  @click="breakdownEnabled = !breakdownEnabled"
                  :title="breakdownEnabled ? 'Compress view' : 'Expand to monthly breakdown'"
                >
                  <span class="pill-left">
                    <CIcon :icon="cilBarChart" size="sm" class="me-1" />
                    {{ breakdownEnabled ? 'Expand' : 'Compress' }}
                  </span>
                  <span class="pill-right" :class="{ on: breakdownEnabled }">
                    {{ breakdownEnabled ? 'ON' : 'OFF' }}
                  </span>
                </button>
              </div>
            </div>
          </div>

          <div v-if="dashboard.isSyncing" class="sync-indicator-mini" title="Background syncing in progress...">
            <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;"></div>
          </div>

          <div
            v-if="dashboard.isTrendsLoading && (!chartData.labels || chartData.labels.length === 0)"
            class="empty-state d-flex flex-column align-items-center justify-content-center py-5"
            :style="{ height: breakdownEnabled ? '560px' : '460px' }"
          >
            <div class="spinner-border text-primary opacity-25" role="status"></div>
            <p class="mt-3 text-muted small text-uppercase fw-bold letter-spacing-1">
              Loading Trends...
            </p>
          </div>

          <div
            v-else-if="(!chartData.labels || chartData.labels.length === 0) && !dashboard.isTrendsLoading"
            class="empty-state d-flex flex-column align-items-center justify-content-center py-5 text-center px-4"
            :style="{ height: breakdownEnabled ? '560px' : '460px' }"
          >
            <p class="text-muted fw-bold mb-1 small">No trend data available.</p>
          </div>

          <div
            v-else
            class="chart-container p-3 pt-3"
            :style="{ height: breakdownEnabled ? '600px' : '500px' }"
          >
            <div
              class="chart-scroll-wrapper"
              :class="{ 'has-scroll': needsScroll, 'breakdown-mode': breakdownEnabled }"
              ref="chartScrollRef"
            >
              <div
                class="chart-inner"
                :style="{
                  width: needsScroll ? chartWidth + 'px' : '100%',
                  height: '100%',
                }"
              >
                <CChartBar
                  :key="`${breakdownEnabled ? 'breakdown' : 'default'}-${dashboard.remoteApiAvailable}`"
                  :data="chartData"
                  :options="{
                    ...chartOptions,
                    maintainAspectRatio: false,
                    plugins: {
                      ...chartOptions.plugins,
                      legend: { display: false },
                    },
                  }"
                  :plugins="[barLabelsPlugin]"
                  style="height: 100%; width: 100%"
                />
              </div>
            </div>
              <div v-if="needsScroll" class="scroll-hint text-muted small mt-2 text-center">
                <CIcon :icon="cilChart" size="sm" class="me-1" />
                Scroll horizontally to see full range
              </div>
          </div>
        </CCol>

        <CCol :xl="3" :lg="12" class="p-0 bg-light-subtle">
          <div
            class="referral-container"
            :style="{ height: breakdownEnabled ? '660px' : '580px', overflowY: 'auto' }"
          >
            <div class="px-1 py-4">
              <div class="mb-4">
                <h6 class="fw-bold text-dark-emphasis mb-0 d-flex align-items-center">
                  <CIcon :icon="cilHospital" class="me-2 text-primary" />
                  Referral Distribution
                </h6>
              </div>

              <div
                v-if="referralData.length > 0"
                class="referral-table-wrapper rounded-3 border shadow-sm bg-white table-responsive"
              >
                <table class="table table-hover align-middle mb-0 referral-table">
                  <thead class="table-light">
                    <tr>
                      <th class="py-2 text-uppercase" style="font-size: 11px">Hospital Name</th>
                      <th
                        class="py-2 text-uppercase"
                        style="font-size: 11px; width: 50px; text-align: center"
                      >
                        %
                      </th>
                      <th
                        class="text-end pe-3 py-2 text-uppercase"
                        style="font-size: 11px; width: 120px; white-space: nowrap"
                      >
                        Number of Patients
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white">
                    <tr v-for="(hosp, index) in referralData" :key="hosp.code" 
                      class="referral-row" 
                      :class="[
                        index % 2 === 0 ? 'even-row' : 'odd-row',
                        index === 0 ? 'top-referral-row' : ''
                      ]"
                    >
                      <td class="py-2 position-relative">
                        <div v-if="index === 0" class="top-badge" style="top: -2px; left: 10px;">TOP #1</div>
                        <div class="d-flex align-items-center gap-2">
                          <span
                            v-if="hosp.name && hosp.name.trim()"
                            class="fw-bold text-dark"
                            style="font-size: 13px"
                            :title="hosp.name"
                          >
                            {{ hosp.name }}
                          </span>
                          <span
                            v-else
                            class="facility-code-name fw-semibold text-muted"
                            style="font-size: 12px"
                            :title="'Facility ' + hosp.code"
                          >
                            Facility {{ hosp.code }}
                          </span>
                        </div>
                      </td>
                      <td
                        style="
                          text-align: center;
                          vertical-align: middle;
                          padding-top: 6px;
                          padding-bottom: 6px;
                        "
                      >
                        <span class="fw-bold" style="font-size: 16px; color: #4f46e5">
                          {{
                            totalReferrals > 0
                              ? (((hosp.count || 0) / totalReferrals) * 100).toFixed(1) + '%'
                              : '0%'
                          }}
                        </span>
                      </td>
                      <td class="text-end pe-3 py-2" style="width: 120px">
                        <div class="d-flex flex-column align-items-end gap-1">
                          <span class="fw-bold text-primary" style="font-size: 15px">{{
                            (hosp.count || 0).toLocaleString()
                          }}</span>
                          <div
                            class="progress shadow-sm"
                            style="width: 60px; height: 5px; border-radius: 4px"
                          >
                            <div
                              class="progress-bar bg-primary"
                              role="progressbar"
                              :style="{
                                width: (hosp.count / (referralData[0]?.count || 1)) * 100 + '%',
                              }"
                            ></div>
                          </div>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div
                v-else
                class="d-flex flex-column align-items-center justify-content-center py-5 opacity-50"
              >
                <CIcon :icon="cilHospital" size="xl" class="mb-2" />
                <p class="small">No referral data found</p>
              </div>
            </div>
          </div>
        </CCol>
      </CRow>
    </CCardBody>
  </CCard>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap');

.service-trends-card {
  border-radius: 24px;
  background: white;
  position: relative;
  transition:
    transform 0.3s ease,
    box-shadow 0.3s ease;
}

.service-trends-card:hover {
  box-shadow: 0 20px 40px rgba(0, 50, 150, 0.08) !important;
}

.sync-indicator-mini {
  position: absolute;
  top: 15px;
  right: 20px;
  z-index: 5;
  background: white;
  padding: 6px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 10px rgba(0, 48, 130, 0.1);
  border: 1px solid rgba(0, 48, 130, 0.05);
}

.glass-header {
  background: rgba(255, 255, 255, 0.4);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid rgba(203, 213, 225, 0.3);
  z-index: 2;
}

.referral-container::-webkit-scrollbar {
  width: 6px;
}

.referral-container::-webkit-scrollbar-thumb {
  background: rgba(0, 0, 0, 0.05);
  border-radius: 10px;
}

.referral-container:hover::-webkit-scrollbar-thumb {
  background: rgba(0, 0, 0, 0.1);
}

.referral-table thead th {
  font-size: 11px;
  text-transform: uppercase;
  color: #64748b;
  font-weight: 700;
  letter-spacing: 0.5px;
  border-bottom: 2px solid #e2e8f0;
}

.referral-row {
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
}

.referral-row.even-row td {
  background-color: #f1f5f9 !important; /* Force visibility even without hover */
}

.referral-row.odd-row td {
  background-color: #ffffff !important;
}

.referral-row:hover {
  background-color: rgba(59, 130, 246, 0.1) !important;
  border-left-color: #3b82f6;
  transform: translateX(2px);
}

.top-referral-row {
  border-left-color: #4f46e5;
  background-color: rgba(79, 70, 229, 0.03) !important;
}

.top-badge {
  position: absolute;
  top: 2px;
  left: 0;
  font-size: 8px;
  background: #4f46e5;
  color: white;
  padding: 1px 6px;
  border-radius: 4px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  transform: translateY(-50%);
  z-index: 1;
  box-shadow: 0 2px 4px rgba(79, 70, 229, 0.2);
}

.premium-stat-pill {
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  overflow: hidden;
  transition: all 0.3s ease;
}

.premium-stat-pill:hover {
  border-color: #3b82f6;
  transform: translateY(-1px);
}

.pill-label {
  border-right: 1px solid #e2e8f0;
  font-weight: 700;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}

.breakdown-pill-btn {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 30px;
  padding: 2px;
  transition: all 0.3s ease;
}

.breakdown-pill-btn.active {
  background: #dbeafe;
  border-color: #3b82f6;
}

.pill-left {
  padding: 4px 12px;
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
}

.active .pill-left {
  color: #2563eb;
}

.pill-right {
  background: #94a3b8;
  color: white;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 10px;
  font-weight: 800;
  min-width: 40px;
  text-align: center;
}

.pill-right.on {
  background: #3b82f6;
}

.chart-scroll-wrapper {
  overflow-x: auto;
  border-radius: 12px;
  padding-bottom: 10px;
  height: 100%; /* Ensure it fills the container and aligns from bottom */
}

.chart-scroll-wrapper.has-scroll::after {
  content: '';
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 40px;
  background: linear-gradient(to right, transparent, rgba(255, 255, 255, 0.8));
  pointer-events: none;
}
</style>
