<script setup>
import { defineAsyncComponent, computed, ref, onMounted, watch, onUnmounted } from 'vue'
import { useDashboardStore } from '@/stores/dashboard'
import { getAutoScrollState } from '@/composables/useAutoScroll'
import LoadingBanner from '@/components/LoadingBanner.vue'
import { CIcon } from '@coreui/icons-vue'
import {
  cilPeople,
  cilMoney,
  cilCreditCard,
  cilGlobeAlt,
  cilBriefcase,
  cilInstitution,
  cilChevronBottom,
  cilChevronTop,
  cilSearch,
} from '@coreui/icons'

const dashboard = useDashboardStore()
const autoScroll = getAutoScrollState()

import { ChartLine, ChartBar } from '../charts/index.js'
import { CChart, CChartPie } from '@coreui/vue-chartjs'
import VueDatePicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'

import DashboardPieCharts from './DashboardPieCharts.vue'
import WidgetsStatsD from '../widgets/SocialStatsWidgets.vue'
import DashboardRadarChart from './DashboardRadarChart.vue'
import DashboardClinicBarChart from './DashboardClinicBarChart.vue'
import ServiceTrendChart from './ServiceTrendChart.vue'

const MainChart = defineAsyncComponent(() => import('./MainChart.vue'))

const isAutoScrollEnabled = computed(() => autoScroll.isEnabled.value)
const hiddenPieCategories = ref([]) // Track hidden categories for pie chart
// Show outage slideshow ONLY when API is unreachable AND grace period has completely expired
const showOutageSlideshow = computed(
  () => {
    // If API is available, definitely NO slideshow
    if (dashboard.remoteApiAvailable !== false) return false

    // If we are in the 5-minute silent window, do NOT show slideshow
    if (dashboard.remoteApiAvailable === false && !dashboard.isOfflineUIReported) return false
    
    // If we are currently using cached fallback data, do NOT show slideshow
    if (dashboard.isUsingCachedData) return false
    
    // If there is any active countdown at all, stay on the dashboard
    if (dashboard.offlineTimerCountdown !== null) return false
    
    // Final check: Only show slideshow if we are truly offline, 5m delay has passed, and 15m grace has expired
    return dashboard.remoteApiAvailable === false && !dashboard.isUsingCachedData && dashboard.offlineTimerCountdown === null
  }
)
const showOfflineIndicator = computed(
  () => dashboard.remoteApiAvailable === false && dashboard.isUsingCachedData && dashboard.isOfflineUIReported
)
const formatOfflineCountdown = computed(() => {
  const seconds = dashboard.offlineTimerCountdown || 0
  if (seconds <= 0) return ''
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}:${String(secs).padStart(2, '0')}`
})
const outageSlides = ['/outage-slides/slide-1.jpg', '/outage-slides/slide-2.jpg', '/outage-slides/slide-3.jpg']
const activeOutageSlide = ref(0)

let syncInterval = null
let outageSlideInterval = null

const startOutageSlideshow = () => {
  if (outageSlideInterval) return

  outageSlideInterval = setInterval(() => {
    activeOutageSlide.value = (activeOutageSlide.value + 1) % outageSlides.length
  }, 5000)
}

const stopOutageSlideshow = () => {
  if (outageSlideInterval) {
    clearInterval(outageSlideInterval)
    outageSlideInterval = null
  }

  activeOutageSlide.value = 0
}

onMounted(() => {
  dashboard.fetchStats()

  // Trigger background sync every 5 minutes for admin users
  syncInterval = setInterval(async () => {
    if (dashboard.isAuthenticated) {
      await dashboard.triggerSync()
    }
  }, 300000) // 5 minutes
})

watch(
  showOutageSlideshow,
  (isActive) => {
    if (isActive) {
      startOutageSlideshow()
      return
    }

    stopOutageSlideshow()
  },
  { immediate: true },
)

onUnmounted(() => {
  dashboard.stopPulse() // Cleanup polling
  if (syncInterval) clearInterval(syncInterval)
  stopOutageSlideshow()
})

const getCategoryColor = (title) => {
  const map = {
    PUBLIC: 'info',
    NHIF: 'success',
    'IPPM - PRIVATE': 'primary',
    'IPPM - CREDIT': 'warning',
    'COST SHARING': 'danger',
    NSSF: 'dark',
    FOREIGNER: 'indigo',
  }
  return map[title] || 'primary'
}

const getCategoryIcon = (title) => {
  const map = {
    PUBLIC: cilPeople,
    NHIF: cilCreditCard,
    'IPPM - PRIVATE': cilBriefcase,
    'IPPM - CREDIT': cilMoney,
    'COST SHARING': cilMoney,
    NSSF: cilInstitution,
    FOREIGNER: cilGlobeAlt,
  }
  return map[title] || cilPeople
}

const patientCategories = computed(() => {
  const colors = {
    PUBLIC: '#007bff',      // Blue
    NHIF: '#28a745',       // Green
    'IPPM - PRIVATE': '#6f42c1', // Purple (distinct from all others)
    'IPPM - CREDIT': '#ffc107',  // Amber
    'COST SHARING': '#E12AFB',   // Magenta (user specified)
    NSSF: '#17a2b8',       // Teal
    FOREIGNER: '#e83e8c',  // Pink
  }

  // Get all categories with their values
  const catTitles = [
    'PUBLIC',
    'NHIF',
    'IPPM - PRIVATE',
    'IPPM - CREDIT',
    'COST SHARING',
    'NSSF',
    'FOREIGNER',
  ]

  const cats = catTitles.map((title) => {
    const metric = dashboard.metrics.find((m) => m.title === title)
    return {
      title,
      value: metric ? metric.value : '0',
      color: colors[title] || '#6c757d',
      numericValue: parseInt((metric?.value || '0').replace(/,/g, '')) || 0,
    }
  })

  // Sort by numeric value descending, but keep NHIF first and FOREIGNER last
  const sortedCats = cats.sort((a, b) => {
    // Always keep NHIF first
    if (a.title === 'NHIF') return -1
    if (b.title === 'NHIF') return 1
    // Always keep FOREIGNER last
    if (a.title === 'FOREIGNER') return 1
    if (b.title === 'FOREIGNER') return -1
    // Sort others by value descending
    return b.numericValue - a.numericValue
  })

  // Calculate sum for the ribbon Total
  const totalSum = sortedCats.reduce((acc, cat) => acc + cat.numericValue, 0)

  return [
    ...sortedCats,
    {
      title: 'Total',
      value: totalSum.toLocaleString(),
      color: 'grey',
    },
  ]
})

// Patient Category Chart Data (Bar + Line combination)
const categoryChartData = computed(() => {
  const categories = patientCategories.value.filter((c) => c.title !== 'Total')
  const values = categories.map((c) => parseInt(c.value.replace(/,/g, '')) || 0)
  const colors = categories.map((c) => c.color)

  return {
    labels: categories.map((c) => c.title),
    datasets: [
      {
        type: 'bar',
        label: 'Patient Count',
        backgroundColor: colors,
        borderColor: colors.map((c) => c),
        borderWidth: 1,
        data: values,
        order: 2,
        barPercentage: 0.7,
        categoryPercentage: 0.8,
        borderRadius: 4,
      },
      {
        type: 'line',
        label: 'Trend Line',
        borderColor: '#003082',
        backgroundColor: 'rgba(0, 48, 130, 0.1)',
        borderWidth: 2,
        fill: false,
        tension: 0.4,
        data: values,
        order: 1,
        pointBackgroundColor: '#003082',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 5,
        pointHoverRadius: 7,
      },
    ],
  }
})

const categoryChartOptions = computed(() => {
  const categories = patientCategories.value.filter((c) => c.title !== 'Total')
  const values = categories.map((c) => parseInt(c.value.replace(/,/g, '')) || 0)
  const maxValue = Math.max(...values, 1)
  const yMax =
    maxValue > 1000
      ? Math.ceil(maxValue / 1000) * 1000 * 1.5
      : maxValue > 100
        ? Math.ceil(maxValue / 100) * 100 * 1.5
        : Math.ceil(maxValue / 10) * 10 * 1.5

  return {
    responsive: true,
    maintainAspectRatio: false,
    layout: {
      padding: { top: 5 },
    },
    plugins: {
      legend: {
        display: true,
        position: 'top',
        labels: {
          usePointStyle: true,
          padding: 15,
          font: { size: 11, weight: '600' },
        },
      },
      tooltip: {
        callbacks: {
          label: (context) => {
            const value = context.raw || 0
            return `${context.dataset.label}: ${value.toLocaleString()}`
          },
        },
      },
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: {
          font: { size: 10, weight: 'bold' },
          color: '#333',
          maxRotation: 45,
          minRotation: 45,
        },
      },
      y: {
        type: 'logarithmic',
        min: 1,
        max: yMax,
        title: {
          display: true,
          text: 'Total Patients (Log Scale)',
          font: { size: 12, weight: 'bold' },
          color: '#333',
        },
        grid: {
          borderDash: [2, 2],
          color: 'rgba(0, 0, 0, 0.1)',
        },
        ticks: {
          callback: (value) => {
            const remain = value / Math.pow(10, Math.floor(Math.log10(value)))
            if (remain === 1 || remain === 2 || remain === 5) {
              return value.toLocaleString()
            }
            return ''
          },
        },
      },
    },
  }
})

// Plugin to display values on bars
const categoryBarLabelsPlugin = {
  id: 'categoryBarLabels',
  afterDatasetsDraw(chart) {
    const { ctx } = chart
    const meta = chart.getDatasetMeta(0)
    if (meta.type !== 'bar') return

    meta.data.forEach((bar, index) => {
      const value = chart.data.datasets[0].data[index]
      if (value === 0) return

      const { x, y } = bar.tooltipPosition()
      const displayValue = new Intl.NumberFormat('en-US').format(value)
      const barColor = chart.data.datasets[0].backgroundColor[index]

      ctx.save()
      ctx.font = "bold 26px 'Outfit', Arial, sans-serif"
      ctx.fillStyle = barColor
      ctx.textAlign = 'center'
      ctx.textBaseline = 'bottom'
      ctx.shadowColor = 'rgba(255, 255, 255, 0.8)'
      ctx.shadowBlur = 4
      ctx.fillText(displayValue, x, y - 6)
      ctx.restore()
    })
  },
}

// Patient Category Pie Chart Data
const categoryPieChartData = computed(() => {
  const categories = patientCategories.value.filter((c) => c.title !== 'Total')
  const values = categories.map((c) => {
    if (hiddenPieCategories.value.includes(c.title)) return 0
    return parseInt(c.value.replace(/,/g, '')) || 0
  })
  const colors = categories.map((c) => c.color)

  return {
    labels: categories.map((c) => c.title),
    datasets: [
      {
        backgroundColor: colors,
        data: values,
        borderWidth: 2,
        borderColor: '#fff',
        radius: '95%',
        cutout: 0,
      },
    ],
  }
})

// Toggle category visibility in pie chart
const togglePieCategory = (categoryTitle) => {
  if (hiddenPieCategories.value.includes(categoryTitle)) {
    hiddenPieCategories.value = hiddenPieCategories.value.filter((c) => c !== categoryTitle)
  } else {
    hiddenPieCategories.value.push(categoryTitle)
  }
}

// Check if category is hidden
const isCategoryHidden = (categoryTitle) => {
  return hiddenPieCategories.value.includes(categoryTitle)
}

const categoryPieChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  layout: {
    padding: 10,
  },
  plugins: {
    legend: {
      display: false,
    },
    tooltip: {
      callbacks: {
        label: (context) => {
          const label = context.label || ''
          const value = context.raw || 0
          const total = context.dataset.data.reduce((a, b) => a + b, 0)
          const percentage = total > 0 ? Math.round((value / total) * 100) : 0
          return `${label}: ${value.toLocaleString()} (${percentage}%)`
        },
      },
    },
  },
}

// Plugin to draw labels
const categoryPieLabelsPlugin = {
  id: 'categoryPieLabels',
  afterDatasetsDraw(chart) {
    const { ctx } = chart
    const meta = chart.getDatasetMeta(0)

    ctx.save()
    meta.data.forEach((element, index) => {
      if (element.hidden) return
      const value = chart.data.datasets[0].data[index]
      if (!value || value === 0) return

      const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0)
      const percentageVal = total > 0 ? (value / total) * 100 : 0
      const percentage = percentageVal.toFixed(1) + '%'
      const midAngle = element.startAngle + (element.endAngle - element.startAngle) / 2
      const angleSpan = ((element.endAngle - element.startAngle) * 180) / Math.PI
      const sliceColor = chart.data.datasets[0].backgroundColor[index]
      const label = chart.data.labels[index] || ''

      if (angleSpan >= 15) {
        const midRadius = element.outerRadius * 0.6 + element.innerRadius * 0.1
        const x = Math.cos(midAngle) * midRadius + element.x
        const y = Math.sin(midAngle) * midRadius + element.y
        ctx.fillStyle = '#fff'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.shadowColor = 'rgba(0,0,0,0.9)'
        ctx.shadowBlur = 5
        ctx.font = "bold 22px 'Outfit', sans-serif"
        ctx.fillText(value.toLocaleString(), x, y - 10)
        ctx.font = "normal 14px 'Outfit', sans-serif"
        ctx.fillText(percentage, x, y + 14)
      } else {
        const r = element.outerRadius
        const cx = element.x
        const cy = element.y
        const x1 = Math.cos(midAngle) * (r * 0.99) + cx
        const y1 = Math.sin(midAngle) * (r * 0.99) + cy
        const x2 = Math.cos(midAngle) * (r * 1.03) + cx
        const y2 = Math.sin(midAngle) * (r * 1.03) + cy
        // FOREIGNER slice always ends just before 12 o'clock — force label right
        const isRight = label === 'FOREIGNER' ? true : x2 > cx
        const x3 = x2 + (isRight ? 8 : -8)
        const y3 = y2
        ctx.shadowBlur = 0
        ctx.strokeStyle = sliceColor
        ctx.lineWidth = 2
        ctx.beginPath()
        ctx.moveTo(x1, y1)
        ctx.lineTo(x2, y2)
        ctx.lineTo(x3, y3)
        ctx.stroke()
        ctx.fillStyle = sliceColor
        ctx.textAlign = isRight ? 'left' : 'right'
        ctx.textBaseline = 'middle'
        ctx.font = "bold 13px 'Outfit', sans-serif"
        const labelX = x3 + (isRight ? 3 : -3)
        ctx.fillText(`${value.toLocaleString()} (${percentage})`, labelX, y3)
      }
    })
    ctx.restore()
  },
}

// ── Emergency Department Breakdown ───────────────────────────────────────────

const EMERGENCY_CLINIC_COLORS = {
  'EMERGENCY MEDICINE': '#dc3545',
  'EMERGENCY - VIP':   '#ff6b7a',
  'MATERNITY EMERGENCY': '#a93226',
}

const emergencyClinicsData = computed(() =>
  [...(dashboard.realClinics || [])]
    .filter((c) => (c.clinic_name || '').toUpperCase().includes('EMERGENCY'))
    .sort((a, b) => b.total_visits - a.total_visits),
)

const emergencyChartData = computed(() => {
  const clinics = emergencyClinicsData.value
  const colors = clinics.map((c) => EMERGENCY_CLINIC_COLORS[c.clinic_name] || '#dc3545')
  const values = clinics.map((c) => c.total_visits || 0)
  return {
    labels: clinics.map((c) => c.clinic_name),
    datasets: [
      {
        type: 'bar',
        label: 'Emergency Visits',
        backgroundColor: colors,
        borderColor: colors,
        borderWidth: 0,
        data: values,
        order: 2,
        barPercentage: 0.55,
        categoryPercentage: 0.65,
        borderRadius: 8,
        borderSkipped: false,
      },
      {
        type: 'line',
        label: 'Trend',
        borderColor: '#7b0d1e',
        backgroundColor: 'rgba(123,13,30,0.08)',
        borderWidth: 2.5,
        fill: true,
        tension: 0.45,
        data: values,
        order: 1,
        pointBackgroundColor: '#7b0d1e',
        pointBorderColor: '#fff',
        pointBorderWidth: 2.5,
        pointRadius: 8,
        pointHoverRadius: 11,
      },
    ],
  }
})

const emergencyChartOptions = computed(() => {
  const values = emergencyClinicsData.value.map((c) => c.total_visits || 0)
  const maxVal = Math.max(...values, 10)
  const yMax = Math.ceil((maxVal * 1.6) / 10) * 10
  return {
    responsive: true,
    maintainAspectRatio: false,
    layout: { padding: { top: 44, bottom: 8, left: 16, right: 16 } },
    plugins: {
      legend: {
        display: true,
        position: 'top',
        labels: {
          usePointStyle: true,
          padding: 18,
          font: { size: 13, weight: '600', family: "'Outfit', sans-serif" },
        },
      },
      tooltip: {
        backgroundColor: 'rgba(120,0,0,0.85)',
        padding: 12,
        titleFont: { size: 13, weight: 'bold' },
        bodyFont: { size: 13 },
        callbacks: {
          label: (ctx) => ` ${ctx.dataset.label}: ${(ctx.raw || 0).toLocaleString()} patients`,
        },
      },
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { font: { size: 13, weight: '700', family: "'Outfit', sans-serif" }, color: '#333', maxRotation: 0 },
      },
      y: {
        beginAtZero: true,
        max: yMax,
        title: {
          display: true,
          text: 'Patients',
          font: { size: 12, weight: 'bold', family: "'Outfit', sans-serif" },
          color: '#555',
        },
        grid: { color: 'rgba(220,53,69,0.08)', borderDash: [3, 3] },
        ticks: { font: { size: 11, family: "'Outfit', sans-serif" }, color: '#666' },
      },
    },
  }
})

const emergencyBarLabelsPlugin = {
  id: 'emergencyBarLabels',
  afterDatasetsDraw(chart) {
    const { ctx } = chart
    const barMeta = chart.getDatasetMeta(0)
    if (!barMeta || barMeta.type !== 'bar') return

    barMeta.data.forEach((bar, index) => {
      const value = chart.data.datasets[0].data[index]
      if (!value) return

      const { x, y } = bar.tooltipPosition()
      const barColor = chart.data.datasets[0].backgroundColor[index]

      ctx.save()
      ctx.font = "bold 28px 'Outfit', Arial, sans-serif"
      ctx.fillStyle = barColor
      ctx.textAlign = 'center'
      ctx.textBaseline = 'bottom'
      ctx.shadowColor = 'rgba(255,255,255,0.95)'
      ctx.shadowBlur = 6
      ctx.fillText(value.toLocaleString(), x, y - 8)
      ctx.restore()
    })
  },
}

// ─────────────────────────────────────────────────────────────────────────────

const formatDate = (dateStr) => {
  if (!dateStr) return 'Date is empty'
  if (typeof dateStr === 'string' && dateStr.includes('T')) {
    return dateStr.split('T')[0]
  }
  return dateStr
}
</script>

<template>
  <div
    class="dashboard-grid px-0 pt-0 pb-3"
    style="position: relative; min-height: 400px; overflow-x: hidden"
  >
    <div v-if="showOutageSlideshow" class="outage-slideshow-shell">
      <div
        class="outage-slideshow-track"
        :style="{ transform: `translateX(-${activeOutageSlide * 100}%)` }"
      >
        <div v-for="slide in outageSlides" :key="slide" class="outage-slide">
          <img :src="slide" alt="Hospital view" class="outage-slide-image" />
        </div>
      </div>
    </div>

    <template v-else>
      <LoadingBanner v-if="dashboard.isLoading" />

      <!-- Future Date Warning Alert -->
      <div
        v-if="dashboard.futureDateWarning"
        class="alert alert-warning alert-dismissible fade show mb-4 shadow-sm"
        role="alert"
      >
        <div class="d-flex align-items-center">
          <div class="me-3">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="32"
              height="32"
              fill="currentColor"
              class="text-warning"
              viewBox="0 0 16 16"
            >
              <path
                d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"
              />
            </svg>
          </div>
          <div>
            <h5 class="alert-heading mb-1 fw-bold">{{ dashboard.futureDateWarning.title }}</h5>
            <p class="mb-0">{{ dashboard.futureDateWarning.message }}</p>
          </div>
        </div>
      </div>

      <div :style="{ opacity: dashboard.isLoading ? 0.6 : 1, transition: 'opacity 0.3s' }">
        <WidgetsStatsD class="mb-2" />

      <ServiceTrendChart />

      <DashboardPieCharts />

      <!-- Patient Categories Ribbon (Original) -->
      <div class="card premium-shadow mb-4 overflow-hidden border-0">
        <div class="card-header bg-white border-0 py-3">
          <h4 class="mb-0 fw-bold text-primary" style="font-size: 22px">
            Patient Category Breakdown
          </h4>
        </div>
        <div class="card-body p-0">
          <div
            class="d-flex align-items-center justify-content-between p-3 overflow-auto custom-scrollbar"
          >
            <div
              v-for="(item, index) in patientCategories.filter((c) => c.title !== 'Total')"
              :key="index"
              class="d-flex flex-column align-items-center px-4 border-end category-ribbon-item"
            >
              <span
                class="text-uppercase fw-bold text-muted mb-1"
                :style="{ color: item.color, fontSize: '0.85rem' }"
                >{{ item.title }}</span
              >
              <div class="d-flex align-items-center">
                <h3 class="mb-0 fw-bold me-2">{{ item.value }}</h3>
              </div>
              <div
                class="progress-line mt-2"
                :style="{
                  backgroundColor: item.color,
                  width: '40px',
                  height: '3px',
                  borderRadius: '2px',
                }"
              ></div>
            </div>

            <!-- Total Section -->
            <div
              class="d-flex flex-column align-items-center px-4 bg-light-gradient category-ribbon-item"
            >
              <span class="text-uppercase fw-bold text-dark mb-1" style="font-size: 0.85rem"
                >Total</span
              >
              <h2 class="mb-0 fw-extrabold text-primary">
                {{ patientCategories.find((c) => c.title === 'Total')?.value || '0' }}
              </h2>
              <div
                class="progress-line mt-2 bg-primary"
                style="width: 50px; height: 4px; border-radius: 2px"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Patient Category Analytics + Emergency — Three Cards on the Same Row -->
      <CRow class="mb-4">
        <!-- Patient Category Bar Chart -->
        <CCol :lg="4">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white border-0 p-3">
              <h5 class="mb-0 fw-bold text-primary" style="font-size: 18px">
                Patient Category Chart
              </h5>
            </div>
            <div class="card-body p-0">
              <div class="chart-container" style="height: 420px">
                <CChart
                  type="bar"
                  :data="categoryChartData"
                  :options="categoryChartOptions"
                  :plugins="[categoryBarLabelsPlugin]"
                  style="height: 100%"
                />
              </div>
            </div>
          </div>
        </CCol>

        <!-- Emergency Department Breakdown -->
        <CCol :lg="4">
          <div class="card h-100 border-0 emergency-breakdown-card">
            <div class="card-header emergency-breakdown-header border-0 py-2 px-3 d-flex align-items-center justify-content-between gap-2">
              <div class="d-flex align-items-center gap-2">
                <div class="emergency-icon-box-sm">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#dc3545" viewBox="0 0 16 16">
                    <path d="M8 1a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 1z"/>
                    <path d="M3.5 0h9A3.5 3.5 0 0 1 16 3.5v9a3.5 3.5 0 0 1-3.5 3.5h-9A3.5 3.5 0 0 1 0 12.5v-9A3.5 3.5 0 0 1 3.5 0zm0 1A2.5 2.5 0 0 0 1 3.5v9A2.5 2.5 0 0 0 3.5 15h9a2.5 2.5 0 0 0 2.5-2.5v-9A2.5 2.5 0 0 0 12.5 1h-9z"/>
                  </svg>
                </div>
                <div>
                  <h5 class="mb-0 fw-bold" style="color: #dc3545; font-size: 18px">Emergency Breakdown</h5>
                  <p class="mb-0 text-muted" style="font-size: 11px; line-height: 1.2">By emergency clinic type</p>
                </div>
              </div>
              <div class="emergency-total-badge-sm">
                <div class="emergency-total-value-sm">{{ (dashboard.realStats?.emergency_visits || 0).toLocaleString() }}</div>
                <div class="emergency-total-label-sm">TOTAL</div>
              </div>
            </div>

            <div class="card-body p-0">
              <div style="height: 340px; padding: 0 8px">
                <CChart
                  type="bar"
                  :data="emergencyChartData"
                  :options="emergencyChartOptions"
                  :plugins="[emergencyBarLabelsPlugin]"
                  style="height: 100%"
                />
              </div>
              <div class="d-flex flex-wrap justify-content-center gap-2 px-3 pb-3">
                <div
                  v-for="clinic in emergencyClinicsData"
                  :key="clinic.clinic_name"
                  class="emergency-clinic-pill-sm"
                  :style="{ borderColor: EMERGENCY_CLINIC_COLORS[clinic.clinic_name] || '#dc3545', background: (EMERGENCY_CLINIC_COLORS[clinic.clinic_name] || '#dc3545') + '10' }"
                >
                  <span class="emergency-pill-dot" :style="{ background: EMERGENCY_CLINIC_COLORS[clinic.clinic_name] || '#dc3545' }"></span>
                  <span class="emergency-pill-name-sm" :style="{ color: EMERGENCY_CLINIC_COLORS[clinic.clinic_name] || '#dc3545' }">{{ clinic.clinic_name }}</span>
                  <span class="emergency-pill-count-sm" :style="{ color: EMERGENCY_CLINIC_COLORS[clinic.clinic_name] || '#dc3545' }">{{ (clinic.total_visits || 0).toLocaleString() }}</span>
                </div>
              </div>
            </div>
          </div>
        </CCol>

        <!-- Patient Category Pie Chart -->
        <CCol :lg="4">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-2 px-3">
              <h5 class="mb-0 fw-bold text-primary" style="font-size: 18px">
                Patient Category Distribution
              </h5>
              <div class="d-flex flex-wrap justify-content-start mt-1 gap-1">
                <span
                  v-for="(item, index) in patientCategories.filter((c) => c.title !== 'Total')"
                  :key="index"
                  class="category-pill clickable-pill"
                  :class="{ 'pill-hidden': isCategoryHidden(item.title) }"
                  :style="{
                    border: `2px solid ${isCategoryHidden(item.title) ? '#ccc' : item.color}`,
                    color: isCategoryHidden(item.title) ? '#999' : item.color,
                    backgroundColor: isCategoryHidden(item.title) ? 'transparent' : `${item.color}15`,
                  }"
                  @click="togglePieCategory(item.title)"
                  :title="isCategoryHidden(item.title) ? 'Click to show' : 'Click to hide'"
                >
                  {{ item.title }}
                </span>
              </div>
            </div>
            <div class="card-body p-0">
              <div class="chart-container" style="height: 420px; margin-top: -10px">
                <CChartPie
                  :data="categoryPieChartData"
                  :options="categoryPieChartOptions"
                  :plugins="[categoryPieLabelsPlugin]"
                  style="height: 100%"
                />
              </div>
            </div>
          </div>
        </CCol>
      </CRow>

      <!-- All Clinics Histogram (Enhanced) -->
      <CRow class="mb-4 pb-4">
        <CCol :md="12">
          <DashboardClinicBarChart />
        </CCol>
      </CRow>
      
        <!-- Auto-scroll boundary marker - last section -->
        <div data-auto-scroll-boundary style="height: 1px;"></div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.outage-slideshow-shell {
  position: relative;
  width: 100%;
  min-height: calc(100vh - 120px);
  overflow: hidden;
  border-radius: 18px;
  background: #f8fafc;
}

.outage-slideshow-track {
  display: flex;
  width: 100%;
  min-height: calc(100vh - 120px);
  transition: transform 0.9s ease-in-out;
}

.outage-slide {
  flex: 0 0 100%;
  min-height: calc(100vh - 120px);
  background: #fff;
}

.outage-slide-image {
  width: 100%;
  height: 100%;
  min-height: calc(100vh - 120px);
  object-fit: cover;
  display: block;
}

/* Scrollbar Styling */
.custom-scrollbar::-webkit-scrollbar {
  height: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: #f1f1f1;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #c1c1c1;
  border-radius: 3px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #a8a8a8;
}

/* Ribbon Item Styles */
.category-ribbon-item {
  min-width: 140px;
  transition: all 0.2s ease;
  cursor: default;
}

.category-ribbon-item:hover {
  transform: translateY(-2px);
}

.bg-danger-soft {
  background-color: #fff1f2;
}

.bg-info-soft {
  background-color: #f0f9ff;
}

.category-pill {
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;
}

.category-pill:hover {
  transform: scale(1.05);
}

.pill-hidden {
  opacity: 0.5;
  text-decoration: line-through;
}

.chart-container {
  padding: 15px;
}

/* ── Emergency Breakdown Chart ─────────────────────── */
.emergency-breakdown-card {
  border-left: 5px solid #dc3545 !important;
  box-shadow: 0 4px 20px rgba(220, 53, 69, 0.08);
  border-radius: 12px;
  overflow: hidden;
}

.emergency-breakdown-header {
  background: linear-gradient(135deg, #fff 0%, #fff5f5 100%);
  border-bottom: 1px solid rgba(220, 53, 69, 0.12) !important;
}

.emergency-icon-box {
  width: 52px;
  height: 52px;
  background: rgba(220, 53, 69, 0.1);
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1.5px solid rgba(220, 53, 69, 0.2);
}

.emergency-total-badge {
  background: rgba(220, 53, 69, 0.07);
  border: 2px solid rgba(220, 53, 69, 0.3);
  border-radius: 14px;
  padding: 10px 24px;
  text-align: center;
  min-width: 150px;
}

.emergency-total-value {
  font-size: 32px;
  font-weight: 900;
  color: #dc3545;
  line-height: 1;
  font-family: 'Outfit', sans-serif;
}

.emergency-total-label {
  font-size: 10px;
  font-weight: 700;
  color: #888;
  letter-spacing: 0.8px;
  margin-top: 3px;
}

.emergency-clinic-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 16px;
  border-radius: 24px;
  border: 2px solid;
  font-family: 'Outfit', sans-serif;
}

.emergency-pill-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.emergency-pill-name {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.3px;
}

.emergency-pill-count {
  font-size: 14px;
  font-weight: 900;
  margin-left: 2px;
}

/* ── Compact emergency card (col-4) ─────────────────── */
.emergency-icon-box-sm {
  width: 38px;
  height: 38px;
  background: rgba(220, 53, 69, 0.1);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1.5px solid rgba(220, 53, 69, 0.2);
}

.emergency-total-badge-sm {
  background: rgba(220, 53, 69, 0.07);
  border: 2px solid rgba(220, 53, 69, 0.3);
  border-radius: 10px;
  padding: 5px 14px;
  text-align: center;
  flex-shrink: 0;
}

.emergency-total-value-sm {
  font-size: 22px;
  font-weight: 900;
  color: #dc3545;
  line-height: 1;
  font-family: 'Outfit', sans-serif;
}

.emergency-total-label-sm {
  font-size: 9px;
  font-weight: 700;
  color: #888;
  letter-spacing: 0.8px;
}

.emergency-clinic-pill-sm {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1.5px solid;
  font-family: 'Outfit', sans-serif;
}

.emergency-pill-name-sm {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.2px;
}

.emergency-pill-count-sm {
  font-size: 12px;
  font-weight: 900;
  margin-left: 1px;
}

@media (max-width: 991.98px) {
  .outage-slideshow-shell,
  .outage-slideshow-track,
  .outage-slide,
  .outage-slide-image {
    min-height: 60vh;
  }
}


</style>
