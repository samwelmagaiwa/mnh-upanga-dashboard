<script setup>
import { computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useDashboardStore } from '@/stores/dashboard'
import { CChart } from '@coreui/vue-chartjs'
import * as XLSX from 'xlsx'

const dashboard = useDashboardStore()
const chartRef = ref(null)
const activeClinicIndex = ref(-1)

const selectedBusinessUnit = computed(() => dashboard.selectedBusinessUnit)
const availableBusinessUnits = computed(() => dashboard.availableBusinessUnits)
const isBUFilterLoading = computed(() => dashboard.isBUFilterLoading)

const onBUChange = (e) => {
  dashboard.filterClinicsByBU(e.target.value || null)
}

let previewStartTimer = null
let previewStopTimer = null
let previewRestartTimer = null
let previewCycleTimer = null

// ── Sorted clinics ────────────────────────────────────────────────────────
const sortedClinics = computed(() =>
  [...(dashboard.realClinics || [])].sort((a, b) => b.total_visits - a.total_visits),
)

// View Toggle State (Default to Top 10)
const showAll = ref(false)

// Display filtered clinics (Top 10 by default)
const displayClinics = computed(() => {
  const all = sortedClinics.value
  return showAll.value ? all : all.slice(0, 10)
})

const chartContainerStyle = computed(() => ({
  width: showAll.value
    ? displayClinics.value.length > 6
      ? `${Math.max(displayClinics.value.length * 160, 900)}px`
      : '100%'
    : '100%',
  height: '100%',
}))

const getChartInstance = () => {
  const refValue = chartRef.value
  if (!refValue) return null

  return refValue.chart || refValue.instance || refValue.$?.exposed?.chart || refValue
}

const clearAutoPreviewTimers = () => {
  clearTimeout(previewStartTimer)
  clearTimeout(previewStopTimer)
  clearTimeout(previewRestartTimer)
  clearInterval(previewCycleTimer)
}

const clearActivePreview = (shouldUpdate = true) => {
  const chartInstance = getChartInstance()
  activeClinicIndex.value = -1

  if (!chartInstance) return

  if (typeof chartInstance.setActiveElements === 'function') {
    chartInstance.setActiveElements([])
  }
  if (chartInstance.tooltip?.setActiveElements) {
    chartInstance.tooltip.setActiveElements([], { x: 0, y: 0 })
  }

  if (shouldUpdate) {
    chartInstance.update()
  }
}

const showPreviewForIndex = async (index) => {
  const chartInstance = getChartInstance()
  if (!chartInstance || !displayClinics.value.length) return

  await nextTick()

  const safeIndex = index % displayClinics.value.length
  const activeElements = [
    { datasetIndex: 0, index: safeIndex },
    { datasetIndex: 1, index: safeIndex },
  ]

  const currentBar = chartInstance.getDatasetMeta(0)?.data?.[safeIndex]
  const previousBar = chartInstance.getDatasetMeta(1)?.data?.[safeIndex]
  const anchorX = currentBar?.x ?? previousBar?.x ?? 0
  const anchorY = Math.min(currentBar?.y ?? Number.MAX_SAFE_INTEGER, previousBar?.y ?? Number.MAX_SAFE_INTEGER)

  activeClinicIndex.value = safeIndex
  if (typeof chartInstance.setActiveElements !== 'function') return

  chartInstance.setActiveElements(activeElements)
  if (chartInstance.tooltip?.setActiveElements) {
    chartInstance.tooltip.setActiveElements(activeElements, {
      x: anchorX,
      y: Number.isFinite(anchorY) ? anchorY : 0,
    })
  }
  chartInstance.update()
}

const startAutoPreviewCycle = async () => {
  clearAutoPreviewTimers()
  if (!displayClinics.value.length) return

  let currentIndex = 0
  await showPreviewForIndex(currentIndex)

  previewCycleTimer = setInterval(() => {
    if (!displayClinics.value.length) return
    currentIndex = (currentIndex + 1) % displayClinics.value.length
    showPreviewForIndex(currentIndex)
  }, 8000)

  previewStopTimer = setTimeout(() => {
    clearInterval(previewCycleTimer)
    clearActivePreview()
    previewRestartTimer = setTimeout(() => {
      startAutoPreviewCycle()
    }, 300000)
  }, 60000)
}

const scheduleAutoPreview = async () => {
  clearAutoPreviewTimers()
  clearActivePreview(false)
  if (!displayClinics.value.length) return

  await nextTick()
  previewStartTimer = setTimeout(() => {
    startAutoPreviewCycle()
  }, 1200)
}

watch([displayClinics, chartRef], () => {
  scheduleAutoPreview()
}, { flush: 'post' })

onMounted(() => {
  scheduleAutoPreview()
})

onUnmounted(() => {
  clearAutoPreviewTimers()
  clearActivePreview(false)
})

const BLUE = '#003082' 
const ORANGE = '#ff6b00' 

function rgba(hex, alpha) {
  const r = parseInt(hex.slice(1, 3), 16)
  const g = parseInt(hex.slice(3, 5), 16)
  const b = parseInt(hex.slice(5, 7), 16)
  return `rgba(${r},${g},${b},${alpha})`
}

// ── Chart data — Grouped Diverging (Current Up, Previous Down) ───────────
const barData = computed(() => {
  const clinics = displayClinics.value
  const isTop10 = !showAll.value

  return {
    labels: clinics.map((c) => {
      const name = c.clinic_name.replace(/^MLG\s+/i, '')
      if (name.length > 10 && name.includes(' ')) {
        const words = name.split(' ')
        const mid = Math.ceil(words.length / 2)
        return [words.slice(0, mid).join(' '), words.slice(mid).join(' ')]
      }
      return name
    }),
    datasets: [
      {
        label: 'Current Visits',
        data: clinics.map((c) => c.total_visits || 0),
        backgroundColor: clinics.map((_, i) => rgba(BLUE, i === activeClinicIndex.value ? 0.5 : 0.22)),
        borderColor: BLUE,
        borderWidth: clinics.map((_, i) => (i === activeClinicIndex.value ? 3 : 2)),
        borderRadius: { topLeft: 5, topRight: 5, bottomLeft: 0, bottomRight: 0 },
        barPercentage: isTop10 ? 0.75 : 0.65,
        categoryPercentage: 0.85,
        hoverBackgroundColor: rgba(BLUE, 0.5),
        hoverBorderWidth: 3,
      },
      {
        label: 'Previous Visits',
        data: clinics.map((c) => -(c.previous_visits || 0)),
        backgroundColor: clinics.map((_, i) => rgba(ORANGE, i === activeClinicIndex.value ? 0.5 : 0.22)),
        borderColor: ORANGE,
        borderWidth: clinics.map((_, i) => (i === activeClinicIndex.value ? 3 : 2)),
        borderRadius: { topLeft: 0, topRight: 0, bottomLeft: 5, bottomRight: 5 },
        barPercentage: isTop10 ? 0.75 : 0.65,
        categoryPercentage: 0.85,
        hoverBackgroundColor: rgba(ORANGE, 0.5),
        hoverBorderWidth: 3,
      },
    ],
  }
})

// ── Chart options ─────────────────────────────────────────────────────────
const chartOptions = computed(() => {
  const clinics = displayClinics.value
  const isTop10 = !showAll.value
  const maxCurrent = Math.max(...clinics.map((c) => c.total_visits || 0), 10)
  const maxPrevious = Math.max(...clinics.map((c) => c.previous_visits || 0), 10)

  const yMax = Math.ceil(maxCurrent * 1.2)
  const yMin = -Math.ceil(maxPrevious * 1.2)

  return {
    responsive: true,
    maintainAspectRatio: false,
    layout: {
      padding: { top: 60, bottom: 0, left: isTop10 ? 5 : 10, right: isTop10 ? 5 : 10 },
    },
    // Ensure tooltip stays open and is easy to see
    interaction: {
      mode: 'index',
      intersect: false,
    },
    plugins: {
      legend: { display: false },
      tooltip: {
        enabled: true,
        backgroundColor: 'rgba(255,255,255,0.98)',
        titleColor: '#0f172a',
        bodyColor: '#334155',
        borderColor: '#003082',
        borderWidth: 1.5,
        padding: 10,
        cornerRadius: 10,
        boxPadding: 4,
        usePointStyle: true,
        titleFont: { size: 13, weight: '800', family: "'Outfit', sans-serif" },
        bodyFont: { size: 12, weight: '600', family: "'Outfit', sans-serif" },
        displayColors: true,
        callbacks: {
          label: (ctx) => {
            const clinic = clinics[ctx.dataIndex]
            if (!clinic) return ''
            const val = Math.abs(ctx.raw)
            return `  ${ctx.dataset.label}: ${val.toLocaleString()}`
          },
          afterLabel: (ctx) => {
            const clinic = clinics[ctx.dataIndex]
            if (!clinic) return ''
            const lines = []
            
            if (ctx.datasetIndex === 0) {
              const pendingLabel = dashboard.isTodaySelected ? 'Await Consultation' : 'Not Consulted'
              lines.push(`  Consulted: ${clinic.consulted || 0}`)
              lines.push(`  ${pendingLabel}: ${clinic.pending || 0}`)
              
              const sign = (clinic.trend || 0) > 0 ? '+' : ''
              lines.push(`  Change: ${sign}${clinic.trend}% (${clinic.interpretation})`)
            } else {
              lines.push(`  Not Consulted: ${clinic.previous_pending || 0}`)
            }
            return lines
          },
        },
      },
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: {
          font: { size: 14, weight: '800', family: "'Outfit', sans-serif" },
          color: '#0f172a',
          maxRotation: 0,
          minRotation: 0,
          autoSkip: false,
          padding: 25,
        },
      },
      y: {
        min: yMin,
        max: yMax,
        title: {
          display: true,
          text: 'Visits (Current Up / Previous Down)',
          color: '#1e293b',
          font: { size: 14, weight: '900', family: "'Outfit', sans-serif" },
        },
        grid: {
          color: (ctx) => (ctx.tick.value === 0 ? 'rgba(0,0,0,0.5)' : 'rgba(148,163,184,0.2)'),
          lineWidth: (ctx) => (ctx.tick.value === 0 ? 2.5 : 1),
        },
        ticks: {
          padding: 8,
          color: '#334155',
          font: { size: 15, weight: '700' },
          callback: (v) => Math.abs(v).toLocaleString(),
          maxTicksLimit: 14,
        },
      },
    },
  }
})

// ── Custom plugin: Absolute values at tips -> dotted line -> Trend % on Current ──
const barLabelsPlugin = {
  id: 'clinicGroupedDivergingLabels',
  afterDatasetsDraw(chart) {
    const { ctx } = chart
    const yAxis = chart.scales.y
    const xAxisY = yAxis.getPixelForValue(0)

    ctx.save()
    ctx.strokeStyle = '#94a3b8' 
    ctx.lineWidth = 2.5 

    const meta0 = chart.getDatasetMeta(0)
    const dataLen = chart.data.labels.length
    for (let i = 0; i < dataLen - 1; i++) {
        // Vertical dividers (Same as before)
      const bar1 = meta0.data[i]
      const bar2 = meta0.data[i + 1]
      if (bar1 && bar2) {
        const dividerX = (bar1.x + bar2.x) / 2
        ctx.beginPath()
        ctx.moveTo(dividerX, chart.chartArea.bottom + 5)
        ctx.lineTo(dividerX, chart.chartArea.bottom + 80) 
        ctx.stroke()
      }
    }
    ctx.restore()

    chart.data.datasets.forEach((dataset, dsIdx) => {
      const meta = chart.getDatasetMeta(dsIdx)
      const isCurrent = dsIdx === 0

      meta.data.forEach((bar, index) => {
        const rawVal = dataset.data[index]
        if (rawVal === undefined || rawVal === null) return

        const clinic = displayClinics.value[index]
        if (!clinic) return

        const { x, y } = bar.tooltipPosition()
        const absoluteVal = Math.abs(rawVal)
        const isPositive = rawVal >= 0

        ctx.save()
        ctx.font = "bold 18px 'Outfit', sans-serif"
        ctx.fillStyle = isPositive ? BLUE : ORANGE
        ctx.textAlign = 'center'
        ctx.textBaseline = isPositive ? 'bottom' : 'top'
        ctx.fillText(absoluteVal.toLocaleString(), x, isPositive ? y - 8 : y + 8)

        // ── Sub-metrics (Consulted / Await) ────────────────
        ctx.save()
        const isToday = dashboard.isTodaySelected
        const consulted = clinic.consulted
        const pending = clinic.pending
        const prevPending = clinic.previous_pending

        ctx.font = "bold 14px 'Outfit', sans-serif"
        
        if (isCurrent) {
          // Current Bar (Up)
          const consultedVal = consulted !== undefined ? consulted : 0
          const pendingVal = pending !== undefined ? pending : 0
          
          if (consultedVal > 0 || pendingVal > 0) {
            const subY = y - 32
            
            // Draw Consulted (Green with Up Arrow)
            ctx.fillStyle = '#16a34a'
            ctx.textAlign = 'right'
            ctx.fillText(`${consultedVal.toLocaleString()} ↑`, x - 5, subY)
            
            // Draw Await Consultation (Pink with Down Arrow)
            ctx.fillStyle = '#ec4899'
            ctx.textAlign = 'left'
            ctx.fillText(`↓ ${pendingVal.toLocaleString()}`, x + 5, subY)
          }
        } else {
          // Previous Bar (Down)
          const prevConsulted = clinic.previous_consulted !== undefined ? clinic.previous_consulted : 0
          const prevPending = clinic.previous_pending !== undefined ? clinic.previous_pending : 0
          
          if (prevConsulted > 0 || prevPending > 0) {
            const subY = y + 32
            ctx.textBaseline = 'top'
            
            // Draw Previous Consulted (Green with Up Arrow)
            ctx.fillStyle = '#16a34a'
            ctx.textAlign = 'right'
            ctx.fillText(`${prevConsulted.toLocaleString()} ↑`, x - 5, subY)
            
            // Draw Previous Not Consulted (Red with Down Arrow)
            ctx.fillStyle = '#dc2626'
            ctx.textAlign = 'left'
            ctx.fillText(`↓ ${prevPending.toLocaleString()}`, x + 5, subY)
          }
        }
        ctx.restore()

        if (isCurrent && clinic.trend !== undefined) {
          const trend = clinic.trend
          const trendStr = `${trend > 0 ? '+' : ''}${trend}%`
          const topSpace = 5
          const trendY = topSpace

          let trendX = x
          if (trend < 0) {
            const prevMeta = chart.getDatasetMeta(1)
            const prevBar = prevMeta.data[index]
            if (prevBar) {
              trendX = prevBar.x
            }
          }

          const xAxisY = chart.scales.y.getPixelForValue(0)
          const lineStart = trend > 0 ? y - 28 : xAxisY - 6
          const lineEnd = trendY + 20

          if (lineStart > lineEnd) {
            ctx.beginPath()
            ctx.setLineDash([3, 4])
            ctx.moveTo(trendX, lineStart)
            ctx.lineTo(trendX, lineEnd)
            ctx.strokeStyle = 'rgba(100,116,139,0.5)'
            ctx.lineWidth = 1.2
            ctx.stroke()
            ctx.setLineDash([])
          }

          ctx.font = "bold 20px 'Outfit', sans-serif"
          const isGrowth = trend >= 0
          ctx.fillStyle = isGrowth ? '#059669' : '#dc2626'
          ctx.textBaseline = 'top'
          ctx.textAlign = 'center'
          ctx.fillText(trendStr, trendX, trendY)

          if (isGrowth) {
            const arrowX = trendX
            const arrowY = trendY + 28
            ctx.beginPath()
            ctx.moveTo(arrowX, arrowY + 8) 
            ctx.lineTo(arrowX, arrowY - 8) 
            ctx.moveTo(arrowX, arrowY - 8)
            ctx.lineTo(arrowX - 5, arrowY - 3)
            ctx.moveTo(arrowX, arrowY - 8)
            ctx.lineTo(arrowX + 5, arrowY - 3)
            ctx.strokeStyle = '#059669'
            ctx.lineWidth = 3
            ctx.stroke()
          } else {
            const arrowX = trendX
            const arrowY = xAxisY
            const prevMeta = chart.getDatasetMeta(1)
            const prevBar = prevMeta.data[index]
            let shaftLength = 18
            if (prevBar) {
              const barHeight = Math.abs(prevBar.y - xAxisY)
              shaftLength = Math.min(18, Math.max(6, barHeight * 0.4))
            }
            ctx.beginPath()
            ctx.moveTo(arrowX, arrowY)
            ctx.lineTo(arrowX, arrowY + shaftLength) 
            ctx.moveTo(arrowX, arrowY + shaftLength)
            ctx.lineTo(arrowX - 5, arrowY + shaftLength - 6)
            ctx.moveTo(arrowX, arrowY + shaftLength)
            ctx.lineTo(arrowX + 5, arrowY + shaftLength - 6)
            ctx.strokeStyle = '#dc2626'
            ctx.lineWidth = 3
            ctx.stroke()
          }
        }
        ctx.restore()
      })
    })

    // ── 4. Zig-Zag Number Line ──
    ctx.save()
    ctx.strokeStyle = '#003082' 
    ctx.lineWidth = 3
    ctx.lineJoin = 'round'
    ctx.lineCap = 'round'
    ctx.shadowColor = 'rgba(255,255,255,0.8)'
    ctx.shadowBlur = 4

    ctx.beginPath()
    let points = []
    const metaCurrent = chart.getDatasetMeta(0)
    const metaPrevious = chart.getDatasetMeta(1)

    chart.data.labels.forEach((_, i) => {
      const barC = metaCurrent.data[i]
      const barP = metaPrevious.data[i]
      if (barC && barP) {
        points.push({ x: barC.x, y: barC.y })
        points.push({ x: barP.x, y: barP.y })
      }
    })

    if (points.length > 1) {
      ctx.beginPath()
      ctx.moveTo(points[0].x, points[0].y)
      for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i]
        const p1 = points[i + 1]
        const cp1x = p0.x + (p1.x - p0.x) * 0.5
        const cp2x = p0.x + (p1.x - p0.x) * 0.5
        ctx.bezierCurveTo(cp1x, p0.y, cp2x, p1.y, p1.x, p1.y)
      }
      ctx.stroke()

      points.forEach((p) => {
        ctx.beginPath()
        ctx.arc(p.x, p.y, 5, 0, Math.PI * 2)
        ctx.fillStyle = '#003082' 
        ctx.fill()
        ctx.strokeStyle = '#fff' 
        ctx.lineWidth = 2 
        ctx.stroke()
      })
    }
    ctx.restore()
  },
}

// ─────────────────────────────────────────────────────────────────────────────
// ── Pending-Patients Modal State ──────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
const pendingModal = ref({
  show: false,
  loading: false,
  error: null,
  clinicName: '',
  pendingCount: 0,
  patients: [],
  searchQuery: '',
})

// Position of "View" buttons — computed from chart bar positions
const barButtonPositions = ref([]) // Array of { x, y, clinicIndex, clinicName, pendingCount }

// Recompute button positions after chart renders
const updateBarButtonPositions = () => {
  const chartInstance = getChartInstance()
  if (!chartInstance || !displayClinics.value.length) {
    barButtonPositions.value = []
    return
  }

  const chartCanvas = chartInstance.canvas
  if (!chartCanvas) return

  const canvasRect = chartCanvas.getBoundingClientRect()
  const containerRect = chartCanvas.parentElement?.getBoundingClientRect()
  if (!containerRect) return

  const positions = []
  const meta0 = chartInstance.getDatasetMeta(0)

  displayClinics.value.forEach((clinic, i) => {
    const bar = meta0?.data?.[i]
    if (!bar) return
    const pendingVal = clinic.pending || 0
    if (pendingVal === 0) return // No button if no pending

    const { x } = bar.tooltipPosition()
    const canvasOffsetX = canvasRect.left - containerRect.left
    const canvasOffsetY = canvasRect.top - containerRect.top

    // Position the button just above the x-axis (zero line)
    const xAxisY = chartInstance.scales.y?.getPixelForValue(0) ?? 0

    positions.push({
      x: x + canvasOffsetX,
      y: xAxisY + canvasOffsetY + 8, // just below the x-axis, above the pending label
      clinicIndex: i,
      clinicName: clinic.clinic_name,
      pendingCount: pendingVal,
    })
  })

  barButtonPositions.value = positions
}

// Watch chart data changes and update positions
watch([displayClinics, showAll], async () => {
  await nextTick()
  setTimeout(updateBarButtonPositions, 300)
}, { flush: 'post' })

onMounted(() => {
  setTimeout(updateBarButtonPositions, 1500)
  window.addEventListener('resize', updateBarButtonPositions)
})

onUnmounted(() => {
  window.removeEventListener('resize', updateBarButtonPositions)
})

// ── Open modal and fetch pending patients for a specific clinic ──
const openPendingModal = async (clinicName, pendingCount) => {
  pendingModal.value = {
    show: true,
    loading: true,
    error: null,
    clinicName,
    pendingCount,
    patients: [],
    searchQuery: '',
  }

  try {
    const { start_date, end_date } = dashboard.calculateDateRange()
    const response = await fetch(
      `${import.meta.env.VITE_API_BASE_URL || '/api/v1'}/dashboard/pending-patients?start_date=${start_date}&end_date=${end_date}&clinic_name=${encodeURIComponent(clinicName)}&_t=${Date.now()}`
    )
    const json = await response.json()
    let patients = json.data || []

    // ── DOCTOR RANKING LOGIC ───────────────────────────────────────────────
    // Score based on how many patients this doctor has pending in THIS clinic
    const docCounts = {}
    patients.forEach(p => {
      const name = (p.bill_doct_name || 'Unassigned').toUpperCase()
      docCounts[name] = (docCounts[name] || 0) + 1
    })

    patients = patients.map(p => ({
      ...p,
      doctorScore: docCounts[(p.bill_doct_name || 'Unassigned').toUpperCase()] || 0
    }))

    // Sort by: Score (High to Low), then Doctor Name (A-Z)
    patients.sort((a, b) => {
      if (b.doctorScore !== a.doctorScore) return b.doctorScore - a.doctorScore
      return (a.bill_doct_name || '').localeCompare(b.bill_doct_name || '')
    })

    pendingModal.value.patients = patients
    pendingModal.value.loading = false
  } catch (err) {
    pendingModal.value.loading = false
    pendingModal.value.error = 'Failed to load patient data. Please try again.'
  }
}

const closePendingModal = () => {
  pendingModal.value.show = false
  pendingModal.value.patients = []
  pendingModal.value.searchQuery = ''
}

const filteredPatients = computed(() => {
  const q = pendingModal.value.searchQuery.trim().toLowerCase()
  if (!q) return pendingModal.value.patients
  return pendingModal.value.patients.filter((p) =>
    (p.mr_number || '').toLowerCase().includes(q) ||
    (p.bill_doct_name || '').toLowerCase().includes(q) ||
    (p.cons_time || '').toLowerCase().includes(q)
  )
})

const formatTime = (t) => {
  if (!t) return '—'
  // Handle HHMM or HH:MM format
  const s = String(t).replace(':', '')
  if (s.length >= 4) {
    return `${s.slice(0, 2)}:${s.slice(2, 4)}`
  }
  return t
}

const pendingModalClinicShortName = computed(() =>
  (pendingModal.value.clinicName || '').replace(/^MLG\s+/i, '')
)

const isToday = computed(() => dashboard.isTodaySelected)
const pendingLabel = computed(() => isToday.value ? 'Await Consultation' : 'Not Consulted')

// Formatting helper for date
const formatSimpleDate = (dateStr) => {
  if (!dateStr) return '—'
  try {
    const d = new Date(dateStr)
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
  } catch { return dateStr }
}

const getScoreClass = (score) => {
  if (score > 10) return 'score-high'
  if (score > 3) return 'score-med'
  return 'score-low'
}

// ─────────────────────────────────────────────────────────────────────────────
// ── Excel Export — Not Consulted / Await Consultation ────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
const exportState = ref({ loading: false })
const toast = ref({ show: false, message: '', type: 'success' })

const showToast = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => { toast.value.show = false }, 4000)
}

// Helper: make a styled cell object
const sc = (value, fillColor = null, bold = false, fontSize = 11, align = 'left', fontColor = '000000') => {
  const cell = { v: value, t: typeof value === 'number' ? 'n' : 's' }
  cell.s = {
    font: { name: 'Calibri', sz: fontSize, bold, color: { rgb: fontColor } },
    alignment: { horizontal: align, vertical: 'center', wrapText: false },
    border: {
      top:    { style: 'thin', color: { rgb: 'D1D5DB' } },
      bottom: { style: 'thin', color: { rgb: 'D1D5DB' } },
      left:   { style: 'thin', color: { rgb: 'D1D5DB' } },
      right:  { style: 'thin', color: { rgb: 'D1D5DB' } },
    },
  }
  if (fillColor) {
    cell.s.fill = { patternType: 'solid', fgColor: { rgb: fillColor } }
  }
  return cell
}

const exportPendingToExcel = async () => {
  if (exportState.value.loading) return
  const allClinics = sortedClinics.value
  if (!allClinics.length) {
    showToast('No clinic data available to export.', 'error')
    return
  }

  exportState.value.loading = true
  showToast('Preparing export… fetching patient data', 'info')

  try {
    const { start_date, end_date } = dashboard.calculateDateRange()
    const apiBase = import.meta.env.VITE_API_BASE_URL || '/api/v1'
    const todayMode = dashboard.isTodaySelected
    const rangeLabel = start_date === end_date ? start_date : `${start_date} to ${end_date}`
    const reportTitle = todayMode
      ? 'AWAIT CONSULTATION REPORT'
      : 'NOT CONSULTED REPORT'
    const pendingColLabel = todayMode ? 'AWAIT CONSULTATION' : 'NOT CONSULTED'
    const prevPendingColLabel = 'PREV. NOT CONSULTED'

    // ── Fetch ALL pending patients in ONE optimized request ──────────────────
    // This is much faster than N requests for N clinics
    const fetchAllPending = async () => {
      try {
        const r = await fetch(
          `${apiBase}/dashboard/pending-patients?start_date=${start_date}&end_date=${end_date}&clinic_name=All Clinics&_t=${Date.now()}`
        )
        const json = await r.json()
        return json.data || []
      } catch (err) {
        console.error("Export fetch failed", err)
        return []
      }
    }

    const allPatients = await fetchAllPending()

    // Group patients by clinic
    const patientMap = {}
    allPatients.forEach(p => {
      const cName = p.clinic_name || 'Unknown'
      if (!patientMap[cName]) patientMap[cName] = []
      patientMap[cName].push(p)
    })

    // Apply Ranking/Scoring to each clinic's patient list
    Object.keys(patientMap).forEach(cName => {
      const clinicPats = patientMap[cName]
      const docCounts = {}
      clinicPats.forEach(p => {
        const dName = (p.bill_doct_name || 'Unassigned').toUpperCase()
        docCounts[dName] = (docCounts[dName] || 0) + 1
      })
      
      patientMap[cName] = clinicPats.map(p => ({
        ...p,
        doctorScore: docCounts[(p.bill_doct_name || 'Unassigned').toUpperCase()] || 0
      })).sort((a, b) => {
        if (b.doctorScore !== a.doctorScore) return b.doctorScore - a.doctorScore
        return (a.bill_doct_name || '').localeCompare(b.bill_doct_name || '')
      })
    })

    // Enhanced Score Label for Legend
    const getScoreBadge = (score) => {
      if (score > 10) return `${score} (HIGH)`
      if (score > 3) return `${score} (MED)`
      return `${score} (LOW)`
    }

    // ── Build workbook ────────────────────────────────────────────────────────
    const wb = XLSX.utils.book_new()

    // Enhanced Colour constants for a "Premium" look
    const CLR_HEADER_BG  = '0F172A'  // Slate 900 (Deep Navy) — title / section headers
    const CLR_COL_HDR    = '1E40AF'  // Blue 800 — column headers
    const CLR_AWAIT      = 'FEF3C7'  // Amber 100 — await consultation rows
    const CLR_AWAIT_NUM  = '92400E'  // Amber 800 — await count text
    const CLR_NOT_CONS   = 'FEE2E2'  // Red 100 — not consulted rows
    const CLR_NOT_NUM    = '991B1B'  // Red 800 — not consulted count
    const CLR_STRIPE     = 'F8FAFC'  // Slate 50 — light grey stripe
    const CLR_WHITE      = 'FFFFFF'
    const CLR_GOLD_TXT   = 'FDE047'  // Yellow 300 (Gold)
    const CLR_WHITE_TXT  = 'FFFFFF'
    const CLR_DARK_TXT   = '1E293B'  // Slate 800
    const CLR_RED_TXT    = 'DC2626'
    const CLR_GREEN_TXT  = '166534'
    const CLR_AMBER_TXT  = 'B45309'
    const CLR_CONSLT     = 'D1FAE5'  // Emerald 100 — consulted rows
    const CLR_PREV_PEND  = 'FEE2E2'  // Red 100 — prev pending fill

    // Title rows for summary sheet
    const summaryRows = []

    // Row 1 — Main title
    summaryRows.push([
      sc('MUHIMBILI NATIONAL HOSPITAL (MNH) – UPANGA', CLR_HEADER_BG, true, 14, 'center', CLR_GOLD_TXT),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
    ])
    // Row 2 — Report title
    summaryRows.push([
      sc(reportTitle, CLR_HEADER_BG, true, 13, 'center', CLR_WHITE_TXT),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
    ])
    // Row 3 — Date range
    summaryRows.push([
      sc(`PERIOD: ${rangeLabel.toUpperCase()}`, CLR_HEADER_BG, true, 11, 'center', 'B0C4DE'),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
    ])
    // Row 4 — generated timestamp
    summaryRows.push([
      sc(`Generated: ${new Date().toLocaleString()}`, CLR_HEADER_BG, false, 10, 'center', '94A3B8'),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
    ])
    // Row 5 — spacer
    summaryRows.push([sc(''), sc(''), sc(''), sc(''), sc(''), sc(''), sc(''), sc('')])

    // Column headers
    summaryRows.push([
      sc('#',                    CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
      sc('CLINIC NAME',          CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
      sc('LEADING DOCTOR',       CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
      sc('TOTAL VISITS',         CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
      sc('CONSULTED',            CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
      sc(pendingColLabel,        CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
      sc('PREV. TOTAL',          CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
      sc(prevPendingColLabel,    CLR_COL_HDR, true, 11, 'center', CLR_WHITE_TXT),
    ])

    // Data rows — one per clinic (Sorted by Pending Count: High -> Low)
    const sortedByPending = [...allClinics].sort((a, b) => (b.pending || 0) - (a.pending || 0))

    sortedByPending.forEach((clinic, i) => {
      const stripe = i % 2 === 0 ? CLR_WHITE : CLR_STRIPE
      const pendingVal = clinic.pending || 0
      const prevPendingVal = clinic.previous_pending || 0

      // Find Leading Doctor for this clinic
      const clinicPats = patientMap[clinic.clinic_name] || []
      const leadingDoc = clinicPats.length > 0 
        ? (clinicPats[0].bill_doct_name || 'Unassigned').toUpperCase()
        : '—'

      // Pending fill: yellow for today (await), red for historical (not consulted)
      const pendingFill = todayMode
        ? (pendingVal > 0 ? CLR_AWAIT    : stripe)
        : (pendingVal > 0 ? CLR_NOT_CONS : stripe)
      const pendingTxtColor = todayMode
        ? (pendingVal > 0 ? CLR_AMBER_TXT : CLR_DARK_TXT)
        : (pendingVal > 0 ? CLR_RED_TXT   : CLR_DARK_TXT)

      const prevPendFill = prevPendingVal > 0 ? CLR_NOT_CONS : stripe
      const prevPendTxt  = prevPendingVal > 0 ? CLR_RED_TXT  : CLR_DARK_TXT

      const consultedVal = clinic.consulted || 0
      const consultedFill = consultedVal > 0 ? CLR_CONSLT : stripe
      const consultedTxt  = consultedVal > 0 ? CLR_GREEN_TXT : CLR_DARK_TXT

      const clinicShort = (clinic.clinic_name || '').replace(/^MLG\s+/i, '')

      summaryRows.push([
        sc(i + 1,                      stripe,       false, 11, 'center'),
        sc(clinicShort.toUpperCase(),  stripe,       false, 11, 'left'),
        sc(leadingDoc,                 stripe,       true,  10, 'left', CLR_DARK_TXT),
        sc(clinic.total_visits || 0,   stripe,       true,  11, 'center'),
        sc(consultedVal,               consultedFill, true, 11, 'center', consultedTxt),
        sc(pendingVal,                 pendingFill,   true, 11, 'center', pendingTxtColor),
        sc(clinic.previous_visits || 0, stripe,       false, 11, 'center'),
        sc(prevPendingVal,              prevPendFill,  true, 11, 'center', prevPendTxt),
      ])
    })

    // Convert to worksheet
    const wsSummary = XLSX.utils.aoa_to_sheet(summaryRows)

    // Column widths
    wsSummary['!cols'] = [
      { wch: 5  },  // #
      { wch: 36 },  // Clinic Name
      { wch: 30 },  // Leading Doctor
      { wch: 14 },  // Total Visits
      { wch: 14 },  // Consulted
      { wch: 22 },  // Await/Not Consulted
      { wch: 14 },  // Prev Total
      { wch: 22 },  // Prev Pending
    ]

    // Row heights
    wsSummary['!rows'] = [
      { hpt: 28 }, // title
      { hpt: 24 }, // subtitle
      { hpt: 18 }, // date
      { hpt: 16 }, // generated
      { hpt: 8  }, // spacer
      { hpt: 22 }, // col headers
    ]

    // Merge title/header rows across all 8 columns (A–H = 0–7)
    wsSummary['!merges'] = [
      { s: { r: 0, c: 0 }, e: { r: 0, c: 7 } },
      { s: { r: 1, c: 0 }, e: { r: 1, c: 7 } },
      { s: { r: 2, c: 0 }, e: { r: 2, c: 7 } },
      { s: { r: 3, c: 0 }, e: { r: 3, c: 7 } },
    ]

    XLSX.utils.book_append_sheet(wb, wsSummary, 'Clinic Summary')

    // ═══════════════════════════════════════════════════════════════════════════
    // SHEET 2 — Per-Clinic Detail (each clinic gets its own section)
    // ═══════════════════════════════════════════════════════════════════════════
    const detailRows = []
    const detailMerges = []

    // Title block
    detailRows.push([
      sc('MUHIMBILI NATIONAL HOSPITAL (MNH) – UPANGA', CLR_HEADER_BG, true, 14, 'center', CLR_GOLD_TXT),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
    ])
    detailRows.push([
      sc(`${reportTitle} — PATIENT DETAIL BY CLINIC`, CLR_HEADER_BG, true, 12, 'center', CLR_WHITE_TXT),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
    ])
    detailRows.push([
      sc(`PERIOD: ${rangeLabel.toUpperCase()}`, CLR_HEADER_BG, true, 11, 'center', 'B0C4DE'),
      sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG), sc('', CLR_HEADER_BG),
    ])
    detailRows.push([sc(''), sc(''), sc(''), sc(''), sc(''), sc('')])

    detailMerges.push(
      { s: { r: 0, c: 0 }, e: { r: 0, c: 5 } },
      { s: { r: 1, c: 0 }, e: { r: 1, c: 5 } },
      { s: { r: 2, c: 0 }, e: { r: 2, c: 5 } },
    )

    let detailRowIdx = 4 // 0-indexed, after title rows

    // Iterate clinics for Detail sheet sorted by pending count to match summary
    sortedByPending.forEach((clinic, ci) => {
      const clinicShort = (clinic.clinic_name || '').replace(/^MLG\s+/i, '')
      const patients = patientMap[clinic.clinic_name] || []
      const pendingVal = clinic.pending || 0
      const prevPendingVal = clinic.previous_pending || 0

      // Clinic section header
      const sectionFill = todayMode ? 'FEF3C7' : 'FEE2E2'  // yellow or red tint
      const sectionBorder = todayMode ? 'F59E0B' : 'DC2626'

      detailRows.push([
        sc(`${ci + 1}. ${clinicShort.toUpperCase()}`, sectionFill, true, 12, 'left', sectionBorder),
        sc('', sectionFill), sc('', sectionFill), sc('', sectionFill), sc('', sectionFill), sc('', sectionFill)
      ])
      detailMerges.push({ s: { r: detailRowIdx, c: 0 }, e: { r: detailRowIdx, c: 5 } })
      detailRowIdx++

      detailRows.push([
        sc(`${pendingColLabel}: ${pendingVal}`, sectionFill, true, 11, 'center', sectionBorder),
        sc(`PREV NOT CONSULTED: ${prevPendingVal}`, sectionFill, true, 11, 'center', CLR_RED_TXT),
        sc(`TOTAL VISITS: ${clinic.total_visits || 0}`, sectionFill, true, 11, 'center', CLR_DARK_TXT),
        sc('', sectionFill), sc('', sectionFill), sc('', sectionFill)
      ])
      detailMerges.push({ s: { r: detailRowIdx, c: 3 }, e: { r: detailRowIdx, c: 5 } })
      detailRowIdx++

      if (patients.length === 0) {
        // No data row
        detailRows.push([
          sc('— No pending patient records found for this period —', CLR_STRIPE, false, 10, 'center', '94A3B8'),
          sc('', CLR_STRIPE), sc('', CLR_STRIPE), sc('', CLR_STRIPE), sc('', CLR_STRIPE), sc('', CLR_STRIPE)
        ])
        detailMerges.push({ s: { r: detailRowIdx, c: 0 }, e: { r: detailRowIdx, c: 5 } })
        detailRowIdx++
      } else {
        // Column headers
        const hdrFill = todayMode ? 'FDE68A' : 'FECACA'
        const hdrTxt  = todayMode ? '92400E' : '991B1B'
        detailRows.push([
          sc('#',              hdrFill, true, 10, 'center', hdrTxt),
          sc('MR NUMBER',      hdrFill, true, 10, 'center', hdrTxt),
          sc('AGE',            hdrFill, true, 10, 'center', hdrTxt),
          sc('BILLING DOCTOR', hdrFill, true, 10, 'center', hdrTxt),
          sc('SCORE LEVEL',    hdrFill, true, 10, 'center', hdrTxt),
          sc('VISIT DATE',     hdrFill, true, 10, 'center', hdrTxt),
          sc('CONS. TIME',     hdrFill, true, 10, 'center', hdrTxt),
        ])
        detailRowIdx++

        patients.forEach((p, pi) => {
          const rowFill = todayMode
            ? (pi % 2 === 0 ? CLR_AWAIT : 'FFFDE7')
            : (pi % 2 === 0 ? CLR_NOT_CONS : 'FFF5F5')
          
          let scoreText = p.doctorScore || 0
          if (p.doctorScore > 10) scoreText += ' (HIGH)'
          else if (p.doctorScore > 3) scoreText += ' (MED)'

          detailRows.push([
            sc(pi + 1,                              rowFill, false, 10, 'center'),
            sc(p.mr_number || '—',                  rowFill, true,  10, 'left'),
            sc(p.pat_age || '—',                    rowFill, false, 10, 'center'),
            sc((p.bill_doct_name || 'Unassigned').toUpperCase(), rowFill, false, 10, 'left'),
            sc(scoreText,                          rowFill, true,  10, 'center', p.doctorScore > 10 ? 'DC2626' : '1E293B'),
            sc(formatSimpleDate(p.visit_date),      rowFill, false, 10, 'center'),
            sc(formatTime(p.cons_time),              rowFill, false, 10, 'center'),
          ])
          detailRowIdx++
        })
      }

      // Spacer between clinics
      detailRows.push([sc(''), sc(''), sc(''), sc(''), sc('')])
      detailRowIdx++
    })

    const wsDetail = XLSX.utils.aoa_to_sheet(detailRows)
    wsDetail['!cols'] = [
      { wch: 6  },  // #
      { wch: 18 },  // MR Number
      { wch: 10 },  // Age
      { wch: 38 },  // Billing Doctor
      { wch: 18 },  // Score Level
      { wch: 16 },  // Date
      { wch: 14 },  // Time
    ]
    wsDetail['!merges'] = detailMerges
    XLSX.utils.book_append_sheet(wb, wsDetail, 'Patient Detail')

    // ── Write file ─────────────────────────────────────────────────────────────
    const mode = todayMode ? 'AwaitConsultation' : 'NotConsulted'
    const fileName = `MNH_${mode}_${start_date}_to_${end_date}.xlsx`
    XLSX.writeFile(wb, fileName, { cellStyles: true, bookSST: false })

    showToast(`✅ Exported: ${fileName}`, 'success')
  } catch (err) {
    console.error('[Export] Error:', err)
    showToast('Export failed. Please try again.', 'error')
  } finally {
    exportState.value.loading = false
  }
}
</script>

<template>
  <div class="card border-0 shadow-sm overflow-hidden histogram-container">
    <!-- Card Header -->
    <div
      class="card-header bg-white border-0 py-0 d-flex justify-content-between align-items-center position-relative"
      style="height: 54px"
    >
      <h5 class="mb-0 fw-bold text-primary" style="font-size: 18px">
        All Clinics Total visits comparison
      </h5>

      <!-- Legend Pill -->
      <div class="chart-legend-overlay d-flex gap-2 align-items-center">
        <div class="legend-badge current-visits d-flex align-items-center gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge-icon">▲</span>
            <span class="badge-text text-nowrap">Current Visits (Up)</span>
          </div>
          <div class="d-flex align-items-center gap-2 ps-2 border-start border-slate-200">
             <span class="fw-black" style="color: #16a34a; font-size: 16px">↑</span>
             <small class="fw-bold" style="color: #16a34a; font-size: 10px; letter-spacing: 0.5px">CONSULTED</small>
             <span class="fw-black ms-1" style="color: #ec4899; font-size: 16px">↓</span>
             <small class="fw-bold" style="color: #ec4899; font-size: 10px; letter-spacing: 0.5px">AWAIT</small>
          </div>
        </div>
        <div class="legend-divider"></div>
        <div class="legend-badge previous-visits d-flex align-items-center gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge-icon">▼</span>
            <span class="badge-text text-nowrap">
              Previous Visits (Down)
              <small
                v-if="sortedClinics[0]?.comparison_dates"
                class="comparison-tag ms-1 text-secondary opacity-75"
              >
                {{
                  sortedClinics[0].comparison_dates.toLowerCase().includes('vs')
                    ? sortedClinics[0].comparison_dates
                    : '(vs ' + sortedClinics[0].comparison_dates + ')'
                }}
              </small>
            </span>
          </div>
          <div class="d-flex align-items-center gap-2 ps-2 border-start border-slate-200">
             <span class="fw-black" style="color: #16a34a; font-size: 16px">↑</span>
             <small class="fw-bold" style="color: #16a34a; font-size: 10px; letter-spacing: 0.5px">CONSULTED</small>
             <span class="fw-black ms-1" style="color: #dc2626; font-size: 16px">↓</span>
             <small class="fw-bold" style="color: #dc2626; font-size: 10px; letter-spacing: 0.5px">NOT CONS.</small>
          </div>
        </div>

        <div v-if="sortedClinics.length > 10" class="legend-divider"></div>

        <button
          v-if="sortedClinics.length > 10"
          type="button"
          class="btn btn-sm toggle-view-btn d-flex align-items-center"
          @click="showAll = !showAll"
          :class="showAll ? 'btn-outline-primary' : 'btn-primary'"
        >
          <span class="btn-text fw-bold">{{ showAll ? 'Top 10' : 'Full' }}</span>
        </button>

        <!-- ── Business Unit Filter ── -->
        <div v-if="availableBusinessUnits.length > 0" class="legend-divider"></div>
        <div v-if="availableBusinessUnits.length > 0" class="bu-filter-wrap">
          <span class="bu-filter-label">BU:</span>
          <select
            class="bu-filter-select"
            :value="selectedBusinessUnit || ''"
            @change="onBUChange"
            :disabled="isBUFilterLoading"
          >
            <option value="">All</option>
            <option v-for="bu in availableBusinessUnits" :key="bu" :value="bu">{{ bu }}</option>
          </select>
          <span v-if="isBUFilterLoading" class="bu-spinner"></span>
        </div>

        <!-- ── Export Button ── -->
        <div class="legend-divider"></div>
        <button
          type="button"
          class="export-pending-btn"
          :class="{ 'export-pending-btn--loading': exportState.loading }"
          @click="exportPendingToExcel"
          :disabled="exportState.loading || !sortedClinics.length"
          :title="`Export ${pendingLabel} data to Excel`"
        >
          <span v-if="exportState.loading" class="export-spinner"></span>
          <svg v-else width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          <span class="export-btn-label">{{ exportState.loading ? 'Exporting…' : 'Export' }}</span>
          <span class="export-btn-sub" :class="isToday ? 'sub--yellow' : 'sub--red'">{{ isToday ? 'AWAIT' : 'NOT CONS.' }}</span>
        </button>
      </div>

      <div style="width: 20px"></div>
    </div>

    <div class="card-body p-0 position-relative">
      <div class="chart-wrapper p-0" :class="{ 'chart-wrapper--fit': !showAll }" style="height: 650px">
        <!-- Chart + View Buttons Container -->
        <div :style="chartContainerStyle" class="position-relative" id="chart-bar-container">
          <CChart
            ref="chartRef"
            type="bar"
            :data="barData"
            :options="chartOptions"
            :plugins="[barLabelsPlugin]"
            style="height: 100%"
          />


        </div>
      </div>
    </div>
  </div>

  <!-- ── Export Toast Notification ── -->
  <Teleport to="body">
    <Transition name="toast-slide">
      <div v-if="toast.show" class="export-toast" :class="`export-toast--${toast.type}`">
        <span class="toast-icon">
          <svg v-if="toast.type === 'success'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          <svg v-else-if="toast.type === 'error'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/></svg>
        </span>
        <span class="toast-msg">{{ toast.message }}</span>
      </div>
    </Transition>
  </Teleport>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- ──  Pending Patients Modal  ─────────────────────────────────────────── -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="pendingModal.show" class="pending-modal-backdrop" @click.self="closePendingModal">
        <div class="pending-modal-panel">
          <!-- Modal Header -->
          <div class="pmodal-header">
            <div class="pmodal-header-left">
              <div class="pmodal-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                  <circle cx="9" cy="7" r="4"/>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                  <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
              </div>
              <div>
                <h4 class="pmodal-title">{{ isToday ? 'Await Consultation' : 'Not Consulted' }} — <span class="pmodal-clinic-name">{{ pendingModalClinicShortName }}</span></h4>
                <p class="pmodal-subtitle">
                  <span class="pmodal-badge-count">{{ pendingModal.pendingCount }}</span> patient{{ pendingModal.pendingCount !== 1 ? 's' : '' }} pending consultation
                </p>
              </div>
            </div>
            <button class="pmodal-close-btn" @click="closePendingModal" title="Close">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <!-- Search bar -->
          <div class="pmodal-search-wrap">
            <svg class="pmodal-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
              v-model="pendingModal.searchQuery"
              class="pmodal-search-input"
              type="text"
              placeholder="Search by MR No., Doctor or Time…"
              autofocus
            />
            <span v-if="pendingModal.searchQuery" class="pmodal-search-count">
              {{ filteredPatients.length }} result{{ filteredPatients.length !== 1 ? 's' : '' }}
            </span>
          </div>

          <!-- Body -->
          <div class="pmodal-body">
            <!-- Loading state -->
            <div v-if="pendingModal.loading" class="pmodal-loading">
              <div class="pmodal-spinner"></div>
              <p class="pmodal-loading-text">Loading patient records…</p>
            </div>

            <!-- Error state -->
            <div v-else-if="pendingModal.error" class="pmodal-error">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              <p>{{ pendingModal.error }}</p>
            </div>

            <!-- Empty state -->
            <div v-else-if="filteredPatients.length === 0" class="pmodal-empty">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              <p v-if="pendingModal.searchQuery">No results match "<strong>{{ pendingModal.searchQuery }}</strong>"</p>
              <p v-else>No pending patients found for this clinic.</p>
            </div>

            <!-- Patient Table -->
            <div v-else class="pmodal-table-wrap">
              <table class="pmodal-table">
                <thead>
                  <tr>
                    <th class="col-no">#</th>
                    <th class="col-mr">MR Number</th>
                    <th class="col-doc">Billing Doctor</th>
                    <th class="col-score">Score Level</th>
                    <th class="col-date">Visit Date</th>
                    <th class="col-time">Cons. Time</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(patient, idx) in filteredPatients" :key="patient.id || idx" class="pmodal-row">
                    <td class="col-no">
                      <span class="row-num">{{ idx + 1 }}</span>
                    </td>
                    <td class="col-mr">
                      <div class="mr-info">
                        <span class="mr-badge">{{ patient.mr_number || '—' }}</span>
                        <span v-if="patient.pat_age" class="age-info">{{ patient.pat_age }}</span>
                      </div>
                    </td>
                    <td class="col-doc">
                      <div class="doctor-cell">
                        <span class="doctor-avatar">{{ (patient.bill_doct_name || 'U')[0].toUpperCase() }}</span>
                        <span class="doctor-name">{{ patient.bill_doct_name || 'Unassigned' }}</span>
                      </div>
                    </td>
                    <td class="col-score">
                      <div class="score-badge" :class="getScoreClass(patient.doctorScore)">
                        {{ patient.doctorScore }}
                      </div>
                    </td>
                    <td class="col-date">
                      <span class="date-badge">{{ formatSimpleDate(patient.visit_date) }}</span>
                    </td>
                    <td class="col-time">
                      <span class="time-badge" :class="{ 'time-badge--early': formatTime(patient.cons_time) < '10:00', 'time-badge--late': formatTime(patient.cons_time) >= '14:00' }">
                        {{ formatTime(patient.cons_time) }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Footer -->
          <div class="pmodal-footer" v-if="!pendingModal.loading && !pendingModal.error">
            <span class="pmodal-footer-info">
              Showing {{ filteredPatients.length }} of {{ pendingModal.patients.length }} records
            </span>
            <button class="pmodal-close-secondary" @click="closePendingModal">Close</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.histogram-container {
  border-radius: 16px;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  border: 1px solid rgba(191, 219, 254, 0.6);
  box-shadow: 0 14px 36px rgba(15, 23, 42, 0.08);
  transform: translateY(-2px);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.chart-legend-overlay {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 10;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  padding: 6px 16px;
  border-radius: 30px;
  border: 1px solid rgba(226, 232, 240, 0.8);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
  white-space: nowrap;
  display: flex;
  gap: 12px;
  align-items: center;
}

.toggle-view-btn {
  border-radius: 20px;
  padding: 4px 14px;
  font-size: 13px;
  transition: all 0.3s ease;
  border-width: 2px;
}

.toggle-view-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.legend-badge {
  padding: 2px 4px;
  border-radius: 6px;
}

.badge-icon {
  font-size: 16px;
  width: 26px;
  height: 26px;
  background: rgba(0, 0, 0, 0.05);
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
}

.current-visits .badge-icon {
  background: rgba(0, 48, 130, 0.1);
  color: #003082;
}
.previous-visits .badge-icon {
  background: rgba(255, 107, 0, 0.1);
  color: #ff6b00;
}

.badge-text {
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.legend-divider {
  width: 1px;
  height: 24px;
  background: #e2e8f0;
  margin: 0 8px;
}

.comparison-tag {
  font-size: 13px;
  font-weight: 500;
  font-style: italic;
}

.chart-wrapper::-webkit-scrollbar {
  height: 6px;
}

.chart-wrapper {
  overflow-x: auto;
}

.chart-wrapper--fit {
  overflow-x: hidden;
}
.chart-wrapper::-webkit-scrollbar-track {
  background: #f8fafc;
}
.chart-wrapper::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 3px;
}

:deep(.histogram-container:hover) {
  transform: translateY(-4px);
  box-shadow: 0 18px 42px rgba(15, 23, 42, 0.12);
}

/* ─── View Button overlaid on chart bars ─────────────────────────────────── */
.bar-view-btn {
  position: absolute;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 3px 9px 3px 7px;
  border-radius: 20px;
  border: none;
  background: linear-gradient(135deg, #ec4899 0%, #be185d 100%);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  font-family: 'Outfit', sans-serif;
  cursor: pointer;
  box-shadow: 0 3px 10px rgba(236, 72, 153, 0.45);
  transition: all 0.2s ease;
  z-index: 20;
  white-space: nowrap;
  letter-spacing: 0.3px;
}

.bar-view-btn:hover {
  transform: translateX(-50%) translateY(-2px) scale(1.06);
  box-shadow: 0 6px 18px rgba(236, 72, 153, 0.6);
  background: linear-gradient(135deg, #f472b6 0%, #ec4899 100%);
}

.bar-view-btn:active {
  transform: translateX(-50%) translateY(0) scale(0.98);
}

.view-btn-icon {
  font-size: 12px;
  line-height: 1;
}

.view-btn-label {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}

.view-btn-badge {
  background: rgba(255, 255, 255, 0.28);
  border-radius: 10px;
  padding: 1px 5px;
  font-size: 10px;
  font-weight: 900;
  min-width: 16px;
  text-align: center;
}

/* ─── Export Button Styling ─────────────────────────────────────────────── */
.export-pending-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 12px;
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 20px;
  color: #fff;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2);
  position: relative;
  overflow: hidden;
}

.export-pending-btn:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.3);
  background: linear-gradient(135deg, #334155 0%, #1e293b 100%);
}

.export-pending-btn:active:not(:disabled) {
  transform: translateY(0);
}

.export-pending-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.export-btn-label {
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}

.export-btn-sub {
  font-size: 10px;
  font-weight: 900;
  padding: 2px 8px;
  border-radius: 10px;
  letter-spacing: 0.5px;
  background: rgba(255, 255, 255, 0.1);
}

.sub--yellow {
  color: #fbbf24;
  background: rgba(251, 191, 36, 0.15);
}

.sub--red {
  color: #f87171;
  background: rgba(248, 113, 113, 0.15);
}

.export-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: #fff;
  animation: pmSpin 0.7s linear infinite;
}

/* ─── Business Unit Filter ────────────────────────────────────────────────── */
.bu-filter-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
}

.bu-filter-label {
  font-size: 11px;
  font-weight: 800;
  color: #64748b;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  white-space: nowrap;
}

.bu-filter-select {
  appearance: none;
  -webkit-appearance: none;
  background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E") no-repeat right 8px center;
  border: 1.5px solid #e2e8f0;
  border-radius: 18px;
  padding: 4px 28px 4px 10px;
  font-size: 12px;
  font-weight: 700;
  font-family: 'Outfit', sans-serif;
  color: #1e293b;
  cursor: pointer;
  transition: all 0.2s ease;
  min-width: 80px;
}

.bu-filter-select:hover {
  border-color: #003082;
  background-color: #eff6ff;
}

.bu-filter-select:focus {
  outline: none;
  border-color: #003082;
  box-shadow: 0 0 0 3px rgba(0, 48, 130, 0.1);
}

.bu-filter-select:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.bu-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid #e2e8f0;
  border-top-color: #003082;
  border-radius: 50%;
  animation: pmSpin 0.7s linear infinite;
  flex-shrink: 0;
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Global styles for the modal (outside scoped) -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<style>
/* Modal Transition */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.28s ease;
}
.modal-fade-enter-active .pending-modal-panel,
.modal-fade-leave-active .pending-modal-panel {
  transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.28s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}
.modal-fade-enter-from .pending-modal-panel {
  transform: translateY(30px) scale(0.97);
  opacity: 0;
}
.modal-fade-leave-to .pending-modal-panel {
  transform: translateY(20px) scale(0.97);
  opacity: 0;
}

/* Backdrop */
.pending-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(10, 15, 35, 0.55);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}

/* Panel */
.pending-modal-panel {
  width: 100%;
  max-width: 720px;
  max-height: 88vh;
  background: #ffffff;
  border-radius: 20px;
  box-shadow:
    0 40px 80px rgba(10, 15, 35, 0.22),
    0 0 0 1px rgba(226, 232, 240, 0.8),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Header */
.pmodal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px 16px;
  background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 40%, #fbf0ff 100%);
  border-bottom: 1px solid rgba(236, 72, 153, 0.12);
  flex-shrink: 0;
}

.pmodal-header-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.pmodal-icon-wrap {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  background: linear-gradient(135deg, #ec4899 0%, #be185d 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  flex-shrink: 0;
  box-shadow: 0 6px 16px rgba(236, 72, 153, 0.4);
}

.pmodal-title {
  margin: 0 0 3px;
  font-size: 17px;
  font-weight: 800;
  color: #1e293b;
  font-family: 'Outfit', sans-serif;
  line-height: 1.2;
}

.pmodal-clinic-name {
  color: #be185d;
}

.pmodal-subtitle {
  margin: 0;
  font-size: 13px;
  color: #64748b;
  font-family: 'Outfit', sans-serif;
  display: flex;
  align-items: center;
  gap: 6px;
}

.pmodal-badge-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #ec4899, #be185d);
  color: #fff;
  font-size: 12px;
  font-weight: 800;
  border-radius: 10px;
  padding: 1px 8px;
  min-width: 24px;
}

.pmodal-close-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid rgba(226, 232, 240, 0.8);
  background: rgba(255, 255, 255, 0.7);
  color: #64748b;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  flex-shrink: 0;
}

.pmodal-close-btn:hover {
  background: #fff;
  color: #dc2626;
  border-color: #fecaca;
  box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);
}

/* Search */
.pmodal-search-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 20px;
  border-bottom: 1px solid #f1f5f9;
  background: #fafbfc;
  flex-shrink: 0;
  position: relative;
}

.pmodal-search-icon {
  color: #94a3b8;
  flex-shrink: 0;
}

.pmodal-search-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: 14px;
  font-family: 'Outfit', sans-serif;
  color: #1e293b;
  outline: none;
}

.pmodal-search-input::placeholder {
  color: #94a3b8;
}

.pmodal-search-count {
  font-size: 12px;
  font-weight: 600;
  color: #ec4899;
  white-space: nowrap;
  font-family: 'Outfit', sans-serif;
}

/* Body */
.pmodal-body {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}

.pmodal-body::-webkit-scrollbar {
  width: 5px;
}
.pmodal-body::-webkit-scrollbar-track { background: #f8fafc; }
.pmodal-body::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 3px; }
.pmodal-body::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }

/* Loading */
.pmodal-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 60px 20px;
}

.pmodal-spinner {
  width: 40px;
  height: 40px;
  border: 3px solid #fce7f3;
  border-top-color: #ec4899;
  border-radius: 50%;
  animation: pmSpin 0.8s linear infinite;
}

@keyframes pmSpin {
  to { transform: rotate(360deg); }
}

.pmodal-loading-text {
  color: #64748b;
  font-size: 14px;
  font-family: 'Outfit', sans-serif;
  margin: 0;
}

/* Error */
.pmodal-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 60px 20px;
  text-align: center;
}

.pmodal-error p {
  color: #dc2626;
  font-size: 14px;
  font-family: 'Outfit', sans-serif;
  margin: 0;
}

/* Empty */
.pmodal-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 60px 20px;
  text-align: center;
}

.pmodal-empty p {
  color: #94a3b8;
  font-size: 14px;
  font-family: 'Outfit', sans-serif;
  margin: 0;
}

/* Table */
.pmodal-table-wrap {
  overflow-x: auto;
}

.pmodal-table {
  width: 100%;
  border-collapse: collapse;
  font-family: 'Outfit', sans-serif;
}

.pmodal-table thead tr {
  background: linear-gradient(90deg, #fdf2f8 0%, #fce7f3 100%);
  position: sticky;
  top: 0;
  z-index: 1;
}

.pmodal-table thead th {
  padding: 11px 16px;
  font-size: 11px;
  font-weight: 800;
  color: #be185d;
  letter-spacing: 0.8px;
  text-transform: uppercase;
  border-bottom: 2px solid rgba(236, 72, 153, 0.15);
  white-space: nowrap;
}

.pmodal-table thead th.col-no {
  width: 46px;
  text-align: center;
}

.pmodal-table thead th.col-mr {
  width: 160px;
}

.pmodal-table thead th.col-doc {
  min-width: 170px;
}

.pmodal-table thead th.col-score {
  width: 100px;
  text-align: center;
}

.pmodal-table thead th.col-date {
  width: 110px;
  text-align: center;
}

.pmodal-table thead th.col-time {
  width: 90px;
  text-align: center;
}

.score-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 28px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 800;
  padding: 0 8px;
}

.score-high { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.score-med { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.score-low { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

.mr_info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.age-info {
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
}

.pmodal-row {
  border-bottom: 1px solid #f1f5f9;
  transition: background 0.15s ease;
}
@media (max-width: 600px) {
  .pmodal-table thead th.col-date,
  .col-date {
    display: none;
  }
}

.pmodal-row:hover {
  background: #fff7fb;
}

.pmodal-row:last-child {
  border-bottom: none;
}

.pmodal-table td {
  padding: 11px 16px;
  vertical-align: middle;
}

.col-no {
  text-align: center;
}

.row-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #f1f5f9;
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
}

.mr-badge {
  display: inline-block;
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  color: #1d4ed8;
  border: 1px solid rgba(59, 130, 246, 0.2);
  border-radius: 8px;
  padding: 3px 10px;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.5px;
  font-family: 'Outfit', monospace;
}

.doctor-cell {
  display: flex;
  align-items: center;
  gap: 9px;
}

.doctor-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: linear-gradient(135deg, #a78bfa 0%, #7c3aed 100%);
  color: #fff;
  font-size: 13px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(124, 58, 237, 0.3);
}

.doctor-name {
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 150px;
}

.date-badge {
  display: inline-block;
  background: #f8fafc;
  color: #475569;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 3px 8px;
  font-size: 12px;
  font-weight: 600;
  font-family: 'Outfit', sans-serif;
  white-space: nowrap;
}

.time-badge {
  display: inline-block;
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid rgba(22, 163, 74, 0.2);
  border-radius: 8px;
  padding: 3px 10px;
  font-size: 13px;
  font-weight: 700;
  font-family: 'Outfit', monospace;
  letter-spacing: 0.5px;
}

.time-badge--early {
  background: #eff6ff;
  color: #1d4ed8;
  border-color: rgba(59, 130, 246, 0.2);
}

.time-badge--late {
  background: #fff7ed;
  color: #c2410c;
  border-color: rgba(234, 88, 12, 0.2);
}

/* Footer */
.pmodal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 20px;
  background: #f8fafc;
  border-top: 1px solid #f1f5f9;
  flex-shrink: 0;
}

.pmodal-footer-info {
  font-size: 12px;
  color: #94a3b8;
  font-family: 'Outfit', sans-serif;
}

.pmodal-close-secondary {
  padding: 6px 18px;
  border-radius: 10px;
  border: 1.5px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  font-size: 13px;
  font-weight: 700;
  font-family: 'Outfit', sans-serif;
  cursor: pointer;
  transition: all 0.2s ease;
}

.pmodal-close-secondary:hover {
  border-color: #ec4899;
  color: #ec4899;
  background: #fdf2f8;
}

/* ─── Toast Notification ─────────────────────────────────────────────── */
.export-toast {
  position: fixed;
  bottom: 30px;
  right: 30px;
  z-index: 10000;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 24px;
  border-radius: 16px;
  background: #ffffff;
  box-shadow: 
    0 20px 40px rgba(0, 0, 0, 0.12),
    0 0 0 1px rgba(0, 0, 0, 0.05);
  font-family: 'Outfit', sans-serif;
  min-width: 300px;
}

.export-toast--success {
  border-left: 5px solid #16a34a;
}
.export-toast--success .toast-icon {
  background: #dcfce7;
  color: #16a34a;
}

.export-toast--info {
  border-left: 5px solid #2563eb;
}
.export-toast--info .toast-icon {
  background: #dbeafe;
  color: #2563eb;
}

.export-toast--error {
  border-left: 5px solid #dc2626;
}
.export-toast--error .toast-icon {
  background: #fee2e2;
  color: #dc2626;
}

.toast-icon {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.toast-msg {
  font-size: 14px;
  font-weight: 600;
  color: #1e293b;
}

/* Toast Animation */
.toast-slide-enter-active {
  transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.toast-slide-leave-active {
  transition: all 0.3s cubic-bezier(0.4, 0, 1, 1);
}
.toast-slide-enter-from {
  opacity: 0;
  transform: translateX(100px) scale(0.9);
}
.toast-slide-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.9);
}
</style>
