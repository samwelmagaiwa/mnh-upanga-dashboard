<script setup>
import { CChart } from '@coreui/vue-chartjs'
import { CIcon } from '@coreui/icons-vue'
import {
  cilBed,
  cilPeople,
  cilMedicalCross,
  cilClock,
  cilXCircle,
  cilChartLine,
  cilHistory,
  cilHospital, 
  cilFile,
  cilUser, 
  cilChevronBottom,
  cilChevronTop,
  cilInfo,
} from '@coreui/icons'
import { useDashboardStore } from '@/stores/dashboard'
import { ref, watch } from 'vue'

const dashboard = useDashboardStore()

const getValue = (key) => {
  return (dashboard.realStats?.[key] || 0).toLocaleString()
}


const getPrevValue = (key) => {
  return (dashboard.previousStats?.[key] || 0).toLocaleString()
}

const showPendingList = ref(false)
const pendingPatients = ref([])
const isListLoading = ref(false)

const togglePendingList = async () => {
  showPendingList.value = !showPendingList.value
  if (showPendingList.value && pendingPatients.value.length === 0) {
    await fetchPendingPatients()
  }
}

const fetchPendingPatients = async () => {
  isListLoading.value = true
  try {
    pendingPatients.value = await dashboard.fetchPendingPatients()
  } catch (error) {
    console.error('Error in SocialStatsWidgets:', error)
  } finally {
    isListLoading.value = false
  }
}

// Refresh list if date range changes and list is open
watch(
  () => [
    dashboard.selectedDay,
    dashboard.selectedWeek,
    dashboard.selectedMonth,
    dashboard.selectedYear,
    dashboard.selectedRange,
  ],
  () => {
    if (showPendingList.value) {
      fetchPendingPatients()
    } else {
      pendingPatients.value = [] // Clear cache to force refresh on next open
    }
  },
  { deep: true },
)
</script>

<template>
  <div class="metrics-grid mb-0 px-0">
    <CRow
      :gutter="3"
      class="row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-6 g-3 px-0 mx-0 flex-nowrap flex-lg-wrap metrics-row"
    >
      <!-- Total OPD -->
      <CCol class="metric-col">
        <div
          class="stat-card premium-shadow shadow-blue"
          style="border-left: 4px solid #3b82f6; border-top: 1px solid #3b82f6"
        >
          <div class="stat-card-header mb-1">
            <div class="stat-icon-wrapper" style="background-color: rgba(59, 130, 246, 0.15)">
              <CIcon :icon="cilPeople" class="stat-icon" style="color: #3b82f6" />
            </div>
            <div class="stat-main-info">
              <h3 class="stat-value" style="color: #3b82f6">{{ getValue('total_patients') }}</h3>
              <span class="stat-label">Total OPD</span>
            </div>
          </div>
          <div
            v-if="dashboard.compLabel"
            class="stat-card-footer mt-auto pt-1"
          >
            <div class="stat-comparison">
              <span class="prev-value text-muted">{{ getPrevValue('total_patients') }}</span>
              <span class="prev-label ms-1">{{ dashboard.compLabel }}</span>
            </div>
          </div>
        </div>
      </CCol>

      <!-- Total Emergency -->
      <CCol class="metric-col">
        <div
          class="stat-card premium-shadow emergency-card shadow-red"
          style="
            border-left: 4px solid #ff0000;
            border-top: 1px solid #ff0000;
            position: relative;
            overflow: hidden;
          "
        >
          <div class="emergency-glow"></div>
          <div class="stat-card-header mb-1" style="position: relative; z-index: 2">
            <div
              class="stat-icon-wrapper emergency-icon-pulse"
              style="background-color: rgba(255, 0, 0, 0.15)"
            >
              <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
                class="stat-icon"
              >
                <rect x="10" y="2" width="4" height="20" rx="1" fill="#FF0000" />
                <rect x="2" y="10" width="20" height="4" rx="1" fill="#FF0000" />
              </svg>
            </div>
            <div class="stat-main-info">
              <div class="d-flex align-items-center mb-0">
                <h3 class="stat-value mb-0" style="color: #ff0000">
                  {{ getValue('emergency_visits') }}
                </h3>
              </div>
              <span class="stat-label">Emergency</span>
            </div>
          </div>
          <div
            v-if="dashboard.compLabel"
            class="stat-card-footer mt-auto pt-1"
            style="position: relative; z-index: 2"
          >
            <div class="stat-comparison">
              <span class="prev-value text-muted">{{ getPrevValue('emergency_visits') }}</span>
              <span class="prev-label ms-1">{{ dashboard.compLabel }}</span>
            </div>
          </div>
        </div>
      </CCol>

      <!-- New Visits -->
      <CCol class="metric-col">
        <div
          class="stat-card premium-shadow shadow-cyan"
          style="border-left: 4px solid #06b6d4; border-top: 1px solid #06b6d4"
        >
          <div class="stat-card-header mb-1">
            <div class="stat-icon-wrapper" style="background-color: rgba(6, 182, 212, 0.15)">
              <CIcon :icon="cilClock" class="stat-icon" style="color: #06b6d4" />
            </div>
            <div class="stat-main-info">
              <h3 class="stat-value" style="color: #06b6d4">{{ getValue('new_visits') }}</h3>
              <span class="stat-label">New Visits</span>
            </div>
          </div>
          <div
            v-if="dashboard.compLabel"
            class="stat-card-footer mt-auto pt-1"
          >
            <div class="stat-comparison">
              <span class="prev-value text-muted">{{ getPrevValue('new_visits') }}</span>
              <span class="prev-label ms-1">{{ dashboard.compLabel }}</span>
            </div>
          </div>
        </div>
      </CCol>

      <!-- Followups -->
      <CCol class="metric-col">
        <div
          class="stat-card premium-shadow shadow-purple"
          style="border-left: 4px solid #6610f2; border-top: 1px solid #6610f2"
        >
          <div class="stat-card-header mb-1">
            <div class="stat-icon-wrapper" style="background-color: rgba(102, 16, 242, 0.15)">
              <CIcon :icon="cilUser" class="stat-icon" style="color: #6610f2" />
            </div>
            <div class="stat-main-info">
              <h3 class="stat-value" style="color: #6610f2">{{ getValue('followups') }}</h3>
              <span class="stat-label">Follow-ups</span>
            </div>
          </div>
          <div
            v-if="dashboard.compLabel"
            class="stat-card-footer mt-auto pt-1"
          >
            <div class="stat-comparison">
              <span class="prev-value text-muted">{{ getPrevValue('followups') }}</span>
              <span class="prev-label ms-1">{{ dashboard.compLabel }}</span>
            </div>
          </div>
        </div>
      </CCol>

      <!-- Total Consulted -->
      <CCol class="metric-col">
        <div
          class="stat-card premium-shadow shadow-green"
          style="border-left: 4px solid #008000; border-top: 1px solid #008000"
        >
          <div class="stat-card-header mb-1">
            <div class="stat-icon-wrapper" style="background-color: rgba(0, 128, 0, 0.15)">
              <CIcon :icon="cilFile" class="stat-icon" style="color: #008000" />
            </div>
            <div class="stat-main-info">
              <h3 class="stat-value" style="color: #008000">
                {{ getValue('consulted') }}
              </h3>
              <span class="stat-label">Consulted</span>
            </div>
          </div>
          <div
            v-if="dashboard.compLabel"
            class="stat-card-footer mt-auto pt-1"
          >
            <div class="stat-comparison">
              <span class="prev-value text-muted">{{ getPrevValue('consulted') }}</span>
              <span class="prev-label ms-1">{{ dashboard.compLabel }}</span>
            </div>
          </div>
        </div>
      </CCol>

      <CCol class="metric-col">
        <div
          class="stat-card premium-shadow"
          :class="[
            dashboard.isTodaySelected ? 'shadow-pink pink-theme-active' : 'shadow-red red-theme-active',
            { expanded: showPendingList },
          ]"
        >
          <!-- Notch Borders -->
          <div class="notch-border top" :class="dashboard.isTodaySelected ? 'pink' : 'red'"></div>
          <div class="notch-border bottom" :class="dashboard.isTodaySelected ? 'pink' : 'red'"></div>
          <!-- Decorative backgrounds for red theme -->
          <div v-if="!dashboard.isTodaySelected" class="decorative-curve-image"></div>
          <div v-if="!dashboard.isTodaySelected" class="vertical-dots"></div>
          <div v-if="!dashboard.isTodaySelected" class="status-info-icon mirror-design">
            <CIcon :icon="cilInfo" />
          </div>

          <div class="stat-card-header mb-1" @click="togglePendingList" style="cursor: pointer">
            <div
              class="stat-icon-wrapper larger-icon"
              :class="{ 'glass-morphism-red': !dashboard.isTodaySelected }"
              :style="{
                backgroundColor: dashboard.isTodaySelected
                  ? 'rgba(236, 72, 153, 0.15)'
                  : 'transparent',
              }"
            >
              <div v-if="!dashboard.isTodaySelected" class="custom-icon-container">
                <!-- Custom SVG: Two people at a desk facing each other -->
                <svg viewBox="0 0 24 24" class="stat-icon main-icon" style="color: #FF0000">
                  <!-- Table -->
                  <rect x="4" y="14" width="16" height="1.5" rx="0.75" fill="currentColor" />
                  <rect x="11" y="15.5" width="2" height="4.5" fill="currentColor" />
                  
                  <!-- Left Person -->
                  <circle cx="6" cy="8" r="2.5" fill="currentColor" />
                  <path d="M2 14c0-2.5 2-4.5 4.5-4.5s4.5 2 4.5 4.5v1H2v-1z" fill="currentColor" />
                  
                  <!-- Right Person -->
                  <circle cx="18" cy="8" r="2.5" fill="currentColor" />
                  <path d="M15 14c0-2.5 2-4.5 4.5-4.5s4.5 2 4.5 4.5v1H15v-1z" fill="currentColor" />
                </svg>
                <!-- Overlaid 'No' symbol -->
                <div class="icon-overlay-no">
                  <CIcon :icon="cilXCircle" size="sm" />
                </div>
              </div>
              <CIcon
                v-else
                :icon="cilClock"
                class="stat-icon"
                :style="{ color: '#ec4899' }"
              />
            </div>
            <div class="stat-main-info">
              <h3
                class="stat-value"
                :class="{ 'red-text-shadow': !dashboard.isTodaySelected }"
                :style="{ color: dashboard.isTodaySelected ? '#ec4899' : '#FF0000' }"
              >
                {{ getValue('pending') }}
                <CIcon
                  :icon="showPendingList ? cilChevronTop : cilChevronBottom"
                  size="sm"
                  class="ms-1 dropdown-arrow"
                />
              </h3>
              <span
                class="stat-label"
                :class="{ 'red-text-shadow-sm': !dashboard.isTodaySelected }"
              >
                {{ dashboard.isTodaySelected ? 'Await Consultation' : 'Not Consulted' }}
              </span>
            </div>
          </div>
          <div
            v-if="dashboard.compLabel"
            class="stat-card-footer mt-auto"
            :class="{ 'design-mirror-footer': !dashboard.isTodaySelected }"
          >
            <div class="stat-comparison">
              <h4 class="prev-value mb-0" :class="{ 'design-mirror-prev': !dashboard.isTodaySelected }">
                {{ getPrevValue('pending') }}
              </h4>
              <span class="prev-label" :class="{ 'design-mirror-label': !dashboard.isTodaySelected }">
                {{ dashboard.compLabel }}
              </span>
            </div>
          </div>

          <!-- Scrollable Patient List -->
          <div v-if="showPendingList" class="pending-list-container">
            <div v-if="isListLoading" class="text-center py-2">
              <div class="spinner-border spinner-border-sm text-warning" role="status"></div>
            </div>
            <div v-else-if="pendingPatients.length === 0" class="no-data-text">
              No pending patients
            </div>
            <div v-else class="patient-list">
              <div v-for="patient in pendingPatients" :key="patient.id" class="patient-item">
                <CIcon :icon="cilUser" size="custom-size" :height="10" class="me-1 opacity-50" />
                <span class="mr-number">{{ patient.mr_number }}</span>
                <span class="visit-time">{{
                  patient.cons_time ? patient.cons_time.substring(0, 5) : ''
                }}</span>
              </div>
            </div>
          </div>
        </div>
      </CCol>
    </CRow>
  </div>
</template>

<style scoped>
.dropdown-arrow {
  transition: transform 0.3s ease;
  font-size: 0.8rem;
  vertical-align: middle;
  opacity: 0.7;
}

.stat-card.expanded {
  border-bottom: 3px solid #ec4899;
  z-index: 1051; /* Higher than siblings when open */
}

.stat-card-footer {
  border-top: 1px solid rgba(0, 0, 0, 0.05);
  line-height: 1;
}

.stat-main-info {
  display: flex;
  flex-direction: column;
  justify-content: center;
  overflow: hidden;
}

.stat-value {
  font-size: 1.8rem; /* Increased from 1.5rem */
  font-weight: 850;
  margin-bottom: 0;
  line-height: 1.1;
  white-space: nowrap;
}

.stat-label {
  font-size: 0.9rem;
  font-weight: 750;
  color: #475569;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  white-space: normal;
  word-break: break-word;
  line-height: 1.2;
}

.stat-comparison {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

.prev-value {
  font-size: 0.9rem; /* Increased from 0.85rem */
  font-weight: 700;
}

.prev-label {
  font-size: 0.65rem; /* Increased */
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}

.stat-card {
  position: relative; /* Base for absolute positioning */
  transition: all 0.3s ease;
}

.pending-list-container {
  position: absolute;
  top: 100%; /* Position right below the card */
  left: 0;
  right: 0;
  z-index: 1050; /* Bootstrap dropdown level */
  max-height: 250px;
  overflow-y: auto;
  padding: 0.5rem;
  background: white; /* Solid white to cover backgrounds */
  border: 1px solid rgba(0, 0, 0, 0.1);
  border-top: none;
  border-radius: 0 0 8px 8px;
  box-shadow:
    0 10px 15px -3px rgba(0, 0, 0, 0.1),
    0 4px 6px -2px rgba(0, 0, 0, 0.05);
  animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.patient-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.patient-item {
  display: flex;
  align-items: center;
  font-size: 0.85rem; /* Increased from 0.75rem */
  font-weight: 600;
  color: #4b5563;
  padding: 4px 8px;
  background: white;
  border-radius: 4px;
  border: 1px solid rgba(0, 0, 0, 0.03);
}

.mr-number {
  flex-grow: 1;
  font-family: 'Monaco', 'Consolas', monospace;
}

.visit-time {
  font-size: 0.65rem;
  color: #9ca3af;
}

.no-data-text {
  font-size: 0.75rem;
  color: #9ca3af;
  text-align: center;
  padding: 1rem;
}

/* Custom Scrollbar */
.pending-list-container::-webkit-scrollbar {
  width: 4px;
}
.pending-list-container::-webkit-scrollbar-track {
  background: transparent;
}
.pending-list-container::-webkit-scrollbar-thumb {
  background: #ec4899;
  border-radius: 10px;
}
/* Colored Shadow Effects - More pronounced default state */
.shadow-blue {
  box-shadow:
    0 6px 20px -4px rgba(59, 130, 246, 0.4),
    0 4px 12px -2px rgba(59, 130, 246, 0.2) !important;
}
.shadow-blue:hover {
  box-shadow: 0 12px 30px -5px rgba(59, 130, 246, 0.6) !important;
}

.shadow-red {
  box-shadow:
    0 6px 20px -4px rgba(220, 53, 69, 0.4),
    0 4px 12px -2px rgba(220, 53, 69, 0.2) !important;
}
.shadow-red:hover {
  box-shadow: 0 12px 30px -5px rgba(220, 53, 69, 0.6) !important;
}

.shadow-green {
  box-shadow:
    0 6px 20px -4px rgba(0, 128, 0, 0.4),
    0 4px 12px -2px rgba(0, 128, 0, 0.2) !important;
}
.shadow-green:hover {
  box-shadow: 0 12px 30px -5px rgba(0, 128, 0, 0.6) !important;
}

.shadow-pink {
  box-shadow:
    0 6px 20px -4px rgba(236, 72, 153, 0.4),
    0 4px 12px -2px rgba(236, 72, 153, 0.2) !important;
}
.shadow-pink:hover {
  box-shadow: 0 12px 30px -5px rgba(236, 72, 153, 0.6) !important;
}

.shadow-red {
  box-shadow:
    0 6px 20px -4px rgba(239, 68, 68, 0.4),
    0 4px 12px -2px rgba(239, 68, 68, 0.2) !important;
}
.shadow-red:hover {
  box-shadow: 0 12px 30px -5px rgba(239, 68, 68, 0.6) !important;
}

.status-info-icon.mirror-design {
  position: absolute;
  top: 15px;
  right: 15px;
  width: 38px;
  height: 38px;
  background: white;
  color: #ff0000;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  box-shadow: 0 0 15px rgba(255, 0, 0, 0.2);
  border: 1.5px solid #ff0000;
}

.status-info-icon.mirror-design :deep(svg) {
  width: 20px;
  height: 20px;
}

.notch-border {
  position: absolute;
  z-index: 5;
}

.notch-border.red { background: #ff0000; }
.notch-border.pink { background: #ec4899; }

.notch-border.top {
  top: 0;
  left: 0;
  width: 70%;
  height: 4px;
  border-radius: 0 0 4px 0;
  clip-path: polygon(0 0, 100% 0, 95% 100%, 0 100%);
}

.notch-border.bottom {
  bottom: 0;
  left: 0;
  width: 35%;
  height: 8px;
  border-radius: 0 4px 0 0;
  clip-path: polygon(0 0, 90% 0, 100% 100%, 0 100%);
}

.pink-theme-active {
  background-color: white !important;
  border-left: 4px solid #ec4899 !important;
  border-top: 1px solid #fdf2f8 !important;
  border-right: 1px solid #fdf2f8 !important;
  border-bottom: 1px solid #fdf2f8 !important;
  position: relative;
}

.red-theme-active {
  background-color: white !important;
  border-left: 4px solid #ff0000 !important;
  border-top: 1px solid #fee2e2 !important;
  border-right: 1px solid #fee2e2 !important;
  border-bottom: 1px solid #fee2e2 !important;
  position: relative;
}

.stat-card.expanded {
  overflow: visible !important;
}

.stat-card:not(.expanded) {
  overflow: hidden !important;
}

.decorative-curve-image {
  position: absolute;
  top: 0;
  right: 0;
  width: 100%;
  height: 100%;
  background: radial-gradient(circle at 80% 20%, rgba(255, 0, 0, 0.05) 0%, transparent 50%),
              radial-gradient(circle at 90% 60%, rgba(255, 0, 0, 0.03) 0%, transparent 40%);
  pointer-events: none;
}

.vertical-dots {
  position: absolute;
  right: 12px;
  bottom: 45px;
  height: 40px;
  width: 4px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  z-index: 5;
}

.vertical-dots::before {
  content: "";
  height: 100%;
  width: 100%;
  background-image: radial-gradient(#ff0000 1.5px, transparent 1.5px);
  background-size: 4px 8px;
}

.design-mirror-footer {
  background: #f1f3f7 !important;
  border-radius: 18px !important;
  padding: 12px 20px !important;
  margin: 10px !important;
  display: flex;
  align-items: center;
}

.design-mirror-prev {
  font-size: 1.5rem !important;
  font-weight: 800 !important;
  color: #2d3748 !important;
}

.design-mirror-label {
  font-size: 0.85rem !important;
  font-weight: 600 !important;
  color: #718096 !important;
  text-transform: uppercase;
  margin-left: 8px;
}

.red-text-shadow {
  text-shadow: 0 2px 8px rgba(255, 0, 0, 0.1);
}

.red-theme-active::before {
  content: "";
  position: absolute;
  top: -10%;
  right: -5%;
  width: 250px;
  height: 150%;
  background: radial-gradient(ellipse at 100% 0%, rgba(255, 0, 0, 0.04) 0%, transparent 70%);
  z-index: 0;
  pointer-events: none;
}

.red-theme-active::after {
  content: "";
  position: absolute;
  top: 0;
  right: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(135deg, transparent 80%, rgba(255, 0, 0, 0.02) 100%);
  pointer-events: none;
}

.larger-icon {
  width: 72px !important;
  height: 72px !important;
  border-radius: 16px !important;
  position: relative;
}

.glass-morphism-red {
  background: rgba(255, 0, 0, 0.05) !important;
  backdrop-filter: blur(4px);
  border: 1px solid rgba(255, 0, 0, 0.2) !important;
  box-shadow: 
    0 8px 32px 0 rgba(255, 0, 0, 0.1),
    inset 0 0 0 1px rgba(255, 255, 255, 0.4) !important;
}

.custom-icon-container {
  position: relative;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.main-icon {
  width: 44px !important;
  height: 44px !important;
}

.icon-overlay-no {
  position: absolute;
  top: -4px;
  right: -4px;
  background: white;
  color: #ff0000;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  z-index: 5;
}

.icon-overlay-no :deep(svg) {
  width: 14px !important;
  height: 14px !important;
}

.stat-card-header {
  position: relative;
  z-index: 2;
}

.stat-card-footer {
  position: relative;
  z-index: 2;
}
.shadow-cyan {
  box-shadow:
    0 6px 20px -4px rgba(6, 182, 212, 0.4),
    0 4px 12px -2px rgba(6, 182, 212, 0.2) !important;
}
.shadow-cyan:hover {
  box-shadow: 0 12px 30px -5px rgba(6, 182, 212, 0.6) !important;
}

.shadow-purple {
  box-shadow:
    0 6px 20px -4px rgba(102, 16, 242, 0.4),
    0 4px 12px -2px rgba(102, 16, 242, 0.2) !important;
}
.shadow-purple:hover {
  box-shadow: 0 12px 30px -5px rgba(102, 16, 242, 0.6) !important;
}

/* Emergency Card Enhancements */
.emergency-card {
  background: linear-gradient(135deg, #ffffff 0%, #fff5f5 100%);
  transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

.emergency-glow {
  position: absolute;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: radial-gradient(circle at center, rgba(220, 53, 69, 0.03) 0%, transparent 70%);
  pointer-events: none;
  z-index: 1;
}

.live-dot {
  width: 6px;
  height: 6px;
  background-color: #dc3545;
  border-radius: 50%;
  margin-right: 4px;
  box-shadow: 0 0 0 rgba(220, 53, 69, 0.4);
  animation: blink 1.5s infinite;
}

.emergency-icon-pulse {
  animation: heartbeat 2s infinite ease-in-out;
}

@keyframes blink {
  0% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
  }
  70% {
    transform: scale(1);
    box-shadow: 0 0 0 6px rgba(220, 53, 69, 0);
  }
  100% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
  }
}

@keyframes heartbeat {
  0% {
    transform: scale(1);
  }
  14% {
    transform: scale(1.1);
  }
  28% {
    transform: scale(1);
  }
  42% {
    transform: scale(1.1);
  }
  70% {
    transform: scale(1);
  }
}

@media (max-width: 991.98px) {
  .metrics-grid {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 1.5rem; /* Space for shadows only when scrolling */
    margin-bottom: 0.5rem;
  }

  .metrics-row {
    flex-wrap: nowrap !important;
  }
}
.metric-col {
  transition: all 0.3s ease;
}

.emergency-card:hover {
  box-shadow: 0 12px 24px rgba(220, 53, 69, 0.15) !important;
}

/* Metrics Grid & Compact Card Styles */
.metrics-grid {
  width: 100%;
  overflow: visible;
}

.stat-card {
  height: 100%;
  padding: 0.7rem 0.6rem !important;
  background: white;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  gap: 0.35rem !important;
  min-height: 105px !important;
}

.stat-card-header {
  display: flex;
  align-items: center;
  gap: 0.4rem !important;
}

.stat-icon-wrapper {
  flex-shrink: 0;
  width: 32px !important;
  height: 32px !important;
  border-radius: 6px !important;
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat-icon {
  width: 16px !important;
  height: 16px !important;
}
</style>
