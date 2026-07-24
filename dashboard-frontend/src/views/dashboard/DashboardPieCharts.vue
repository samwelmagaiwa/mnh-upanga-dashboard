<script setup>
import { computed } from 'vue'
import { CChart, CChartPie } from '@coreui/vue-chartjs'
import { useDashboardStore } from '@/stores/dashboard'
import { Chart as ChartJS, registerables } from 'chart.js'

ChartJS.register(...registerables)

const dashboard = useDashboardStore()

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  cutout: '75%',
  animation: {
    duration: 1500,
    easing: 'easeOutQuart',
  },
  plugins: {
    legend: {
      position: 'top',
      labels: {
        usePointStyle: true,
        padding: 15,
        font: {
          size: 13,
          family: "'Outfit', sans-serif",
          weight: '700',
        },
      },
    },
    tooltip: {
      backgroundColor: 'rgba(0, 0, 0, 0.8)',
      padding: 12,
      bodyFont: { size: 14 },
      titleFont: { size: 14, weight: 'bold' },
      callbacks: {
        label: (context) => {
          const label = context.label || ''
          const value = context.raw || 0
          const total = context.dataset.data.reduce((a, b) => a + b, 0)
          const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0
          return ` ${label}: ${value.toLocaleString()} (${percentage}%)`
        },
      },
    },
  },
}

const pieChartOptions = {
  ...chartOptions,
  cutout: 0,
  layout: {
    padding: {
      top: 20,
      bottom: 20,
      left: 20,
      right: 20,
    },
  },
}

const genericPieLabelsPlugin = {
  id: 'genericPieLabels',
  afterDatasetsDraw(chart) {
    const { ctx } = chart
    const meta = chart.getDatasetMeta(0)

    ctx.save()
    ctx.font = "bold 22px 'Outfit', sans-serif"
    ctx.fillStyle = '#fff'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'

    meta.data.forEach((element, index) => {
      if (element.hidden) return

      const value = chart.data.datasets[0].data[index]
      if (!value || value === 0) return

      const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0)
      const pct = (value / total) * 100

      // Skip labels for slices too small to display cleanly inside — tooltip covers them
      if (pct < 5) return

      const percentage = pct.toFixed(1) + '%'

      const model = element
      const midRadius = model.outerRadius * 0.65 + model.innerRadius * 0.35
      const midAngle = model.startAngle + (model.endAngle - model.startAngle) / 2
      const x = Math.cos(midAngle) * midRadius + model.x
      const y = Math.sin(midAngle) * midRadius + model.y

      // Shadow for readability
      ctx.shadowColor = 'rgba(0,0,0,0.5)'
      ctx.shadowBlur = 4

      // Draw Value and Percentage
      ctx.fillText(value.toLocaleString(), x, y - 10)
      ctx.font = "normal 16px 'Outfit', sans-serif"
      ctx.fillText(percentage, x, y + 12)
      ctx.font = "bold 22px 'Outfit', sans-serif" // Reset for next iteration
    })
    ctx.restore()
  },
}

const polarChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  layout: {
    padding: {
      top: 0,
      bottom: 20,
      left: 0,
      right: 100,
    },
  },
  scales: {
    r: {
      pointLabels: {
        display: false, // Disabled standard labels to draw them manually in the plugin
      },
      ticks: {
        display: true,
        backdropColor: 'rgba(255, 255, 255, 0.4)',
        backdropPadding: 4,
        color: '#475569',
        z: 10,
        maxTicksLimit: 6, // Reduced from 9 to increase spacing between round lines
        font: { size: 13, weight: 'bold' },
      },
      grid: {
        color: 'rgba(148, 163, 184, 0.25)', // Slightly darker for prominence
        lineWidth: 1.5, // Increased from 1.0 to increase "line size"
      },
      angleLines: {
        color: 'rgba(148, 163, 184, 0.1)',
      },
    },
  },
  plugins: {
    ...chartOptions.plugins,
    legend: {
      display: true,
      position: 'left',
      align: 'start',
      labels: {
        ...chartOptions.plugins.legend.labels,
        padding: 20,
        boxWidth: 15,
        boxHeight: 15,
        font: {
          size: 15,
          weight: '800',
          family: "'Outfit', sans-serif",
        },
        color: '#1e293b',
      },
    },
  },
  animation: chartOptions.animation,
}

const genderChartData = computed(() => {
  const stats = dashboard.pieStats?.gender || { male: 0, female: 0, no_gender: 0, unknown: 0 }
  return {
    labels: ['Male', 'Female', 'Other', 'Not Specified'],
    datasets: [
      {
        label: 'Patients',
        backgroundColor: [
          'rgba(51, 153, 255, 0.7)',
          'rgba(229, 83, 83, 0.7)',
          'rgba(249, 177, 21, 0.7)',
          'rgba(157, 165, 177, 0.7)',
        ],
        borderColor: ['#3399ff', '#e55353', '#f9b115', '#9da5b1'],
        borderWidth: 1,
        data: [stats.male, stats.female, stats.no_gender, stats.unknown],
      },
    ],
  }
})

const visitTypeChartData = computed(() => {
  const stats = dashboard.pieStats?.visit_type || { new: 0, followup: 0 }
  return {
    labels: ['New', 'Follow-Up'],
    datasets: [
      {
        backgroundColor: ['rgba(46, 184, 92, 0.7)', 'rgba(249, 177, 21, 0.7)'],
        borderColor: ['#2eb85c', '#f9b115'],
        borderWidth: 1,
        data: [stats.new, stats.followup],
      },
    ],
  }
})

const ageGroupChartData = computed(() => {
  const stats = dashboard.pieStats?.age_groups || {
    neonate: 0,
    infant: 0,
    child: 0,
    adolescent: 0,
    adult: 0,
    elderly: 0,
  }

  const hasData = Object.values(stats).some((v) => v > 0)

  if (!hasData) {
    return {
      labels: ['No Data'],
      datasets: [{ backgroundColor: ['#eaeaeb'], data: [0] }],
    }
  }

  return {
    labels: [
      'Neonate: 0-28 days',
      'Infant: 29d-1y',
      'Child: 1-12y',
      'Adolescent: 13-17y',
      'Adult: 18-59y',
      'Elderly: 60+y',
    ],
    datasets: [
      {
        backgroundColor: [
          'rgba(50, 31, 219, 0.6)',
          'rgba(51, 153, 255, 0.6)',
          'rgba(46, 184, 92, 0.6)',
          'rgba(249, 177, 21, 0.6)',
          'rgba(229, 83, 83, 0.6)',
          'rgba(99, 111, 131, 0.6)',
        ],
        borderColor: ['#321fdb', '#3399ff', '#2eb85c', '#f9b115', '#e55353', '#636f83'],
        borderWidth: 1,
        data: [
          stats.neonate,
          stats.infant,
          stats.child,
          stats.adolescent,
          stats.adult,
          stats.elderly,
        ],
      },
    ],
  }
})

const plugins = []
// 4. Custom Leader Lines Plugin for Polar Area
const polarPlugins = [
  {
    id: 'polarLeaderLines',
    afterDatasetsDraw(chart) {
      if (chart.config.type !== 'polarArea') return
      const {
        ctx,
        scales: { r },
      } = chart
      const meta = chart.getDatasetMeta(0)

      ctx.save()

      meta.data.forEach((element, index) => {
        if (element.hidden) return

        const model = element
        const angle = model.startAngle + (model.endAngle - model.startAngle) / 2
        const cosA = Math.cos(angle)
        const sinA = Math.sin(angle)

        // ── 1. Prepare label strings ──────────────────────────────────────────
        const originalLabel = chart.data.labels[index] || ''
        const cleanLabel = originalLabel.includes(': ')
          ? originalLabel.split(': ')[1]
          : originalLabel
        const ageStats = dashboard.pieStats?.age_groups || {}
        const ageValues = [
          ageStats.neonate || 0,
          ageStats.infant || 0,
          ageStats.child || 0,
          ageStats.adolescent || 0,
          ageStats.adult || 0,
          ageStats.elderly || 0,
        ]
        const val = ageValues[index] ?? 0
        const valueText = `(${val.toLocaleString()})`

        // ── 2. Set font early so we can measure text width for clamping ───────
        ctx.font = "800 18px 'Outfit', sans-serif"
        const maxTextW =
          Math.max(ctx.measureText(cleanLabel).width, ctx.measureText(valueText).width) + 12 // safety buffer

        // ── 3. Compute arrowhead target position ──────────────────────────────
        const labelPadding = 40 // px beyond r.drawingArea
        // Upper-half sectors nudged downward so they clear the scale tick labels
        const topNudge = sinA < 0 ? Math.abs(sinA) * 40 : 0
        let xLabel = cosA * (r.drawingArea + labelPadding) + model.x
        let yLabel = sinA * (r.drawingArea + labelPadding) + model.y + topNudge

        // ── 4. Clamp using CANVAS bounds (not chart-area) to prevent arrow reversal ──
        // Using canvas dims ensures the arrow always points AWAY from center.
        const cw = chart.width
        const ch = chart.height
        const lineH = 22
        // Half-width of the text block that extends away from the arrowhead tip
        const halfText = maxTextW + 10
        const yBlock = lineH * 2 + 10
        // Keep arrowhead tip inside canvas with enough room for text beyond it
        xLabel = Math.max(
          cosA < 0 ? halfText + 4 : 4,
          Math.min(xLabel, cosA >= 0 ? cw - halfText - 4 : cw - 4),
        )
        yLabel = Math.max(
          sinA < 0 ? yBlock + 4 : 4,
          Math.min(yLabel, sinA >= 0 ? ch - yBlock - 4 : ch - 4),
        )

        // ── 5. Draw leader line ───────────────────────────────────────────────
        const borderColor = meta.data[index].options.borderColor
        ctx.strokeStyle = borderColor + 'AA'
        ctx.fillStyle = borderColor + 'AA'
        ctx.lineWidth = 1.2
        ctx.setLineDash([2, 2])

        const xStart = Math.cos(angle) * (model.outerRadius + 2) + model.x
        const yStart = Math.sin(angle) * (model.outerRadius + 2) + model.y
        ctx.beginPath()
        ctx.moveTo(xStart, yStart)
        ctx.lineTo(xLabel, yLabel)
        ctx.stroke()

        // ── 6. Draw arrowhead ─────────────────────────────────────────────────
        const headlen = 8
        const arrowAngle = Math.atan2(yLabel - yStart, xLabel - xStart)
        ctx.setLineDash([])
        ctx.beginPath()
        ctx.moveTo(xLabel, yLabel)
        ctx.lineTo(
          xLabel - headlen * Math.cos(arrowAngle - Math.PI / 6),
          yLabel - headlen * Math.sin(arrowAngle - Math.PI / 6),
        )
        ctx.lineTo(
          xLabel - headlen * Math.cos(arrowAngle + Math.PI / 6),
          yLabel - headlen * Math.sin(arrowAngle + Math.PI / 6),
        )
        ctx.closePath()
        ctx.fill()

        // ── 7. Draw text (value first, then name) ─────────────────────────────
        ctx.fillStyle = borderColor || '#475569'
        const gap = 6
        const textX = xLabel + cosA * gap
        const textY = yLabel + sinA * gap

        ctx.textAlign = cosA >= 0 ? 'left' : 'right'
        const goesDown = sinA >= 0
        ctx.textBaseline = goesDown ? 'top' : 'bottom'
        const step = goesDown ? lineH : -lineH

        ctx.fillText(valueText, textX, textY)
        ctx.fillText(cleanLabel, textX, textY + step)
      })
      ctx.restore()
    },
  },
]
</script>

<template>
  <div class="row mb-4 mx-0 px-0">
    <!-- Patient Age Distribution (Polar Area - Expanded to col-6) -->
    <div class="col-lg-6 col-md-12">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 font-weight-bold pb-0 pt-3">
          <h6 class="mb-0 fw-bold text-center text-primary" style="font-size: 20px">
            Patient Age Distribution
          </h6>
        </div>
        <div class="card-body p-0" style="min-height: 450px; height: 450px">
          <!-- Subtle top-right sync indicator for the card -->
          <div v-if="dashboard.isSyncing" class="sync-indicator-mini" title="Background syncing in progress...">
            <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;"></div>
          </div>

          <div v-if="ageGroupChartData.labels[0] === 'No Data' && !dashboard.isLoading" 
               class="d-flex align-items-center justify-content-center h-100 text-center text-muted">
            <p class="mb-0">No Data Available</p>
          </div>
          <div v-else style="height: 100%; width: 100%">
            <CChart
              type="polarArea"
              :data="ageGroupChartData"
              :options="polarChartOptions"
              :plugins="polarPlugins"
              style="height: 100%; width: 100%"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Patient Gender Distribution (Donut - col-3) -->
    <div class="col-lg-3 col-md-6">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 font-weight-bold pb-0 pt-3">
          <h6 class="mb-2 fw-bold text-center text-primary" style="font-size: 20px">
            Patient Gender Distribution
          </h6>
        </div>
        <div class="card-body p-2" style="min-height: 450px; height: 450px">
          <div
            v-if="!dashboard.pieStats || !dashboard.pieStats.gender"
            class="d-flex align-items-center justify-content-center h-100 text-center text-muted"
          >
            <p class="mb-0">No Data Available</p>
          </div>
          <CChartPie
            v-else
            :data="genderChartData"
            :options="pieChartOptions"
            :plugins="[genericPieLabelsPlugin]"
            style="height: 100%"
          />
        </div>
      </div>
    </div>

    <!-- Visit Type Distribution (Donut - col-3) -->
    <div class="col-lg-3 col-md-6">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 font-weight-bold pb-0 pt-3">
          <h6 class="mb-2 fw-bold text-center text-primary" style="font-size: 20px">
            New vs Follow-Up Visits
          </h6>
        </div>
        <div class="card-body p-2" style="min-height: 450px; height: 450px">
          <div
            v-if="!dashboard.pieStats || !dashboard.pieStats.visit_type"
            class="d-flex align-items-center justify-content-center h-100 text-center text-muted"
          >
            <p class="mb-0">No Data Available</p>
          </div>
          <CChartPie
            v-else
            :data="visitTypeChartData"
            :options="chartOptions"
            :plugins="[genericPieLabelsPlugin]"
            style="height: 100%"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sync-indicator-mini {
  position: absolute;
  top: 10px;
  right: 15px;
  z-index: 5;
  background: rgba(255, 255, 255, 0.8);
  padding: 4px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}
</style>
