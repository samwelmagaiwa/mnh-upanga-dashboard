<script setup>
import { computed, ref, onMounted, watch } from 'vue'
import { useColorModes } from '@coreui/vue'
import {
  CHeader,
  CContainer,
  CHeaderNav,
  CNavItem,
  CNavLink,
  CButton,
  CDropdown,
  CDropdownToggle,
  CDropdownMenu,
  CDropdownItem,
  CFormSelect,
  CFormInput,
  CModal,
  CModalHeader,
  CModalBody,
  CModalFooter,
  CProgress,
  CProgressBar,
  CSpinner,
} from '@coreui/vue'
import CIcon from '@coreui/icons-vue'
import { useDashboardStore } from '@/stores/dashboard'
import AutoScrollControl from '@/components/AutoScrollControl.vue'
import VueDatePicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'
import * as XLSX from 'xlsx'

const gapsModalVisible = ref(false)
const forceGapRepair = ref(false)
const dashboard = useDashboardStore()
const { colorMode, setColorMode } = useColorModes('coreui-free-vue-admin-template-theme')
const isRemoteOffline = computed(() => dashboard.remoteApiAvailable === false && dashboard.isOfflineUIReported)
const remoteStatusLabel = computed(() => (isRemoteOffline.value ? 'OFFLINE' : 'ONLINE'))
const formatOfflineTimer = computed(() => {
  const seconds = dashboard.offlineTimerCountdown || 0
  if (seconds <= 0) return ''
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}:${String(secs).padStart(2, '0')}`
})

const headerClassNames = ref('mb-4 p-0')
const modalVisible = ref(false)

const periodOptions = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
  { value: 'year', label: 'Year' },
  { value: 'range', label: 'Select by Range' },
]

const filterMode = ref(dashboard.selectedPeriod)

const handleSync = async () => {
  if (!confirm('Start background sync for the selected period?')) return
  try {
    const res = await dashboard.syncCurrentRange()
    alert('Sync Task Started: ' + res.message + '\nData will refresh in the background.')
  } catch (error) {
    alert('Sync trigger failed. Please check the console.')
  }
}

const handleCheckGaps = async () => {
  await dashboard.fetchGaps()
  gapsModalVisible.value = true
}

const handleExport = async () => {
  await dashboard.exportToExcel()
  modalVisible.value = false
}

watch(filterMode, (newVal) => {
  dashboard.selectedPeriod = newVal
})

watch(
  () => dashboard.selectedPeriod,
  (newVal) => {
    filterMode.value = newVal
  },
)

onMounted(() => {
  document.addEventListener('scroll', () => {
    if (document.documentElement.scrollTop > 0) {
      headerClassNames.value = 'mb-4 p-0 shadow-sm'
    } else {
      headerClassNames.value = 'mb-4 p-0'
    }
  })
})
</script>

<template>
  <CHeader position="sticky" :class="headerClassNames">
    <CContainer class="border-bottom px-4" fluid>
      <CHeaderNav class="d-flex align-items-center">
        <CNavItem>
          <CNavLink href="/" class="d-flex align-items-center p-0">
            <img src="@/assets/images/muhilogo.jpg" height="40" class="me-2" alt="Logo" />
            <span class="fw-bold text-primary d-none d-md-inline">MNH UPANGA DASHBOARD</span>
          </CNavLink>
        </CNavItem>
      </CHeaderNav>

      <CHeaderNav class="mx-auto d-flex align-items-center">
        <CFormSelect
          v-model="filterMode"
          :options="periodOptions"
          style="width: 150px; margin-right: 5px"
        />
        <VueDatePicker
          v-if="filterMode === 'day'"
          v-model="dashboard.selectedDay"
          format="dd/MM/yyyy"
          auto-apply
          :enable-time-picker="false"
          placeholder="Select day"
          style="width: 200px; margin-right: 10px"
        />
        <VueDatePicker
          v-if="filterMode === 'week'"
          v-model="dashboard.selectedWeek"
          week-picker
          auto-apply
          week-start="monday"
          placeholder="Select week"
          style="width: 250px; margin-right: 10px"
        />
        <VueDatePicker
          v-if="filterMode === 'month'"
          v-model="dashboard.selectedMonth"
          month-picker
          auto-apply
          format="MMMM yyyy"
          placeholder="Select month"
          style="width: 200px; margin-right: 10px"
        />
        <VueDatePicker
          v-if="filterMode === 'year'"
          v-model="dashboard.selectedYear"
          year-picker
          auto-apply
          placeholder="Select year"
          style="width: 150px; margin-right: 10px"
        />
        <VueDatePicker
          v-if="filterMode === 'range'"
          v-model="dashboard.selectedRange"
          range
          auto-apply
          placeholder="Select date range"
          style="width: 250px; margin-right: 10px"
        />
        <CButton
          color="primary"
          style="margin-right: 10px; font-weight: 600"
          @click="$router.push('/reports')"
        >
          <CIcon icon="cil-description" class="me-2" />
          Report
        </CButton>
        <CButton
          color="warning"
          variant="outline"
          class="me-2 d-flex align-items-center position-relative"
          @click="handleSync"
          :disabled="dashboard.isSyncing"
          title="Background Sync"
        >
          <!-- Small dot if sync is needed -->
          <span 
            v-if="dashboard.isSyncNeeded" 
            class="position-absolute translate-middle p-1 bg-danger border border-light rounded-circle" 
            style="top: 5px; left: 5px"
          >
            <span class="visually-hidden">Sync Needed</span>
          </span>

          <CIcon
            :icon="dashboard.isSyncing ? 'cil-reload' : 'cil-sync'"
            :class="{ 'me-2': true, spinner: dashboard.isSyncing }"
          />
          {{ dashboard.isSyncing ? (dashboard.syncProgress > 0 ? `Syncing ${dashboard.syncProgress}%` : 'Syncing...') : 'Sync' }}
        </CButton>
        <CButton
          color="danger"
          variant="outline"
          class="me-2"
          @click="handleCheckGaps"
          title="Check for missing data"
        >
          <CIcon icon="cil-search" class="me-2" />
          Gaps
        </CButton>
        <CDropdown variant="nav-item" :caret="false" class="me-2">
          <CDropdownToggle color="secondary" variant="outline" class="d-flex align-items-center">
            <CIcon icon="cil-settings" class="me-2" />
            Controls
          </CDropdownToggle>
          <CDropdownMenu class="p-3 shadow-lg border-0" style="min-width: 350px; border-radius: 16px;">
            <div class="mb-2 fw-bold text-muted small text-uppercase letter-spacing-1">Page Settings</div>
            <AutoScrollControl />
          </CDropdownMenu>
        </CDropdown>
        <CButton color="primary" @click="modalVisible = true">Export</CButton>
      </CHeaderNav>

      <CHeaderNav class="ms-auto d-flex align-items-center">
        <div
          class="remote-status-pill me-3"
          :class="isRemoteOffline ? 'status-offline' : 'status-online'"
        >
          <span class="status-orb"></span>
          <div class="status-content">
            <span class="status-label">{{ isRemoteOffline ? 'OFFLINE' : 'ONLINE' }}</span>
            <span v-if="isRemoteOffline" class="status-timer">{{ formatOfflineTimer }}</span>
          </div>
        </div>

        <CDropdown variant="nav-item" placement="bottom-end" class="me-3">
          <CDropdownToggle :caret="false">
            <CIcon v-if="colorMode === 'dark'" icon="cil-moon" size="lg" />
            <CIcon v-else-if="colorMode === 'light'" icon="cil-sun" size="lg" />
            <CIcon v-else icon="cil-contrast" size="lg" />
          </CDropdownToggle>
          <CDropdownMenu>
            <CDropdownItem @click="setColorMode('light')">
              <CIcon icon="cil-sun" class="me-2" /> Light
            </CDropdownItem>
            <CDropdownItem @click="setColorMode('dark')">
              <CIcon icon="cil-moon" class="me-2" /> Dark
            </CDropdownItem>
            <CDropdownItem @click="setColorMode('auto')">
              <CIcon icon="cil-contrast" class="me-2" /> Auto
            </CDropdownItem>
          </CDropdownMenu>
        </CDropdown>

        <CButton
          v-if="!dashboard.isAuthenticated"
          component="a"
          href="/#/login"
          color="primary"
          variant="outline"
          class="px-4"
        >
          Login
        </CButton>
        <CButton
          v-else
          component="a"
          href="/#/dashboard"
          color="success"
          variant="outline"
          class="px-4"
        >
          Admin
        </CButton>
      </CHeaderNav>
    </CContainer>

    <!-- Background Sync Progress Bar -->
    <CContainer fluid v-if="dashboard.isSyncing" class="px-4 pb-2">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <small class="text-warning fw-bold d-flex align-items-center">
          <CIcon icon="cil-sync" class="me-2 spinner" />
          {{ dashboard.syncMessage || 'Background Syncing...' }}
        </small>
        <div class="d-flex align-items-center">
          <small class="text-muted me-3">{{ dashboard.syncProgress }}%</small>
          <CButton
            size="sm"
            color="light"
            variant="outline"
            class="py-0 px-2 fw-bold text-danger border-danger"
            style="font-size: 0.75rem"
            @click="dashboard.cancelSync()"
          >
            DISMISS
          </CButton>
        </div>
      </div>
      <CProgress
        :height="6"
        color="warning"
        variant="striped"
        animated
        :value="dashboard.syncProgress"
        class="shadow-sm"
      />
    </CContainer>

    <!-- Export Modal -->
    <CModal
      :visible="modalVisible"
      @close="() => (modalVisible = false)"
      backdrop="static"
      keyboard="false"
    >
      <CModalHeader>
        <CIcon icon="cil-spreadsheet" class="text-primary me-2" />
        Confirm Export
      </CModalHeader>
      <CModalBody class="text-center">
        <CIcon icon="cil-spreadsheet" size="xxl" class="mb-3 text-primary" />
        <p class="fs-5">
          Are you sure you want to export this data to Excel for the current period ({{
            dashboard.selectedPeriod
          }})?
        </p>
        <small class="text-muted"
          >This action will generate an Excel file with the current dashboard data.</small
        >
        <div v-if="dashboard.isDetailedLoading" class="mt-3 text-primary">
          <CSpinner size="sm" class="me-2" />
          <span class="fw-bold">Fetching latest data for export...</span>
          <p class="small text-muted mt-1">Downloading 16,000+ records can take a few seconds.</p>
        </div>
      </CModalBody>
      <CModalFooter>
        <CButton color="danger" variant="outline" @click="modalVisible = false">
          <CIcon icon="cil-x" class="me-1" />
          Cancel
        </CButton>
        <CButton color="primary" @click="handleExport" :disabled="dashboard.isDetailedLoading">
          <CIcon v-if="!dashboard.isDetailedLoading" icon="cil-check" class="me-1" />
          <CSpinner v-else size="sm" class="me-1" />
          {{ dashboard.isDetailedLoading ? 'Loading Data...' : 'Yes, Export' }}
        </CButton>
      </CModalFooter>
    </CModal>

    <!-- Gaps Modal -->
    <CModal :visible="gapsModalVisible" @close="() => (gapsModalVisible = false)" size="lg">
      <CModalHeader>
        <CIcon icon="cil-warning" class="text-danger me-2" />
        Data Reliability Report (Gaps)
      </CModalHeader>
      <CModalBody>
        <div v-if="dashboard.gapsLoading" class="text-center py-4">
          <CIcon icon="cil-reload" size="xl" class="spinner text-primary mb-2" />
          <p>Analyzing data for gaps...</p>
        </div>
        <div v-else-if="dashboard.gaps.length === 0" class="text-center py-4">
          <CIcon icon="cil-check-circle" size="xxl" class="text-success mb-3" />
          <p class="fs-5 text-success fw-bold">
            No gaps detected for the selected period! Data is complete.
          </p>
        </div>
        <div v-else>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-danger fw-bold mb-0">
              Detected {{ dashboard.gaps.length }} days with missing or unexpected zero data:
            </p>
            <div class="d-flex align-items-center me-3">
              <CFormCheck
                id="forceRepair"
                label="Force re-sync even if data already exists"
                v-model="forceGapRepair"
                class="me-3"
              />
              <CButton
                color="danger"
                size="sm"
                @click="
                  async () => {
                    try {
                      const res = await dashboard.repairGaps(
                        dashboard.gaps.map((g) => g.date),
                        forceGapRepair,
                      )
                      const ignoredSummary = (res.ignored_dates || [])
                        .map((item) => `${item.date}: ${item.reason}`)
                        .join('\n')
                      alert(
                        'Gap repair started: ' +
                          res.message +
                          (ignoredSummary ? '\n\nIgnored dates:\n' + ignoredSummary : ''),
                      )
                      gapsModalVisible = false
                      forceGapRepair = false
                    } catch (e) {
                      alert('Repair failed: ' + (e.response?.data?.error || e.message))
                    }
                  }
                "
                :disabled="dashboard.isSyncing"
              >
                <CIcon icon="cil-sync" class="me-1" />
                Sync Missing Data
              </CButton>
            </div>
          </div>
          <table class="table table-striped">
            <thead>
              <tr>
                <th>Date</th>
                <th>Status</th>
                <th>Reason</th>
                <th>Raw Visits</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="gap in dashboard.gaps" :key="gap.date">
                <td>{{ gap.date }}</td>
                <td>
                  <span :class="gap.status === 'MISSING' ? 'badge bg-danger' : 'badge bg-warning'">
                    {{ gap.status }}
                  </span>
                </td>
                <td>{{ gap.reason }}</td>
                <td>{{ gap.raw_visits ?? 0 }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </CModalBody>
      <CModalFooter>
        <CButton color="secondary" @click="gapsModalVisible = false">Close</CButton>
      </CModalFooter>
    </CModal>
  </CHeader>
</template>

<style scoped>
.remote-status-pill {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  padding: 3px 14px 3px 3px;
  border-radius: 999px;
  border: 3px solid #bfc4ca;
  background: linear-gradient(180deg, #fafafa 0%, #d9dde2 52%, #f8fafc 100%);
  box-shadow:
    inset 0 2px 1px rgba(255, 255, 255, 0.95),
    inset 0 -2px 2px rgba(100, 116, 139, 0.22),
    0 6px 16px rgba(15, 23, 42, 0.16);
  transition:
    background 0.3s ease,
    border-color 0.3s ease,
    box-shadow 0.3s ease,
    transform 0.3s ease;
  overflow: visible;
  isolation: isolate;
  white-space: nowrap;
}

.status-content {
  display: flex;
  flex-direction: column;
  gap: 1px;
  align-items: flex-start;
  justify-content: center;
  height: 100%;
}

.status-orb {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 3px solid #eef2f6;
  box-shadow:
    inset 0 8px 12px rgba(255, 255, 255, 0.38),
    inset 0 -6px 10px rgba(0, 0, 0, 0.12),
    0 0 0 4px #bfc4ca,
    0 3px 8px rgba(15, 23, 42, 0.18);
  flex-shrink: 0;
}

.status-label {
  font-size: 0.75rem;
  font-weight: 900;
  letter-spacing: 0.05em;
  line-height: 1.1;
  color: #fff;
  text-shadow: 0 2px 5px rgba(0, 0, 0, 0.16);
  white-space: nowrap;
}

.status-timer {
  font-size: 0.75rem;
  font-weight: 900;
  font-family: 'Monaco', 'Courier New', monospace;
  letter-spacing: 0.05em;
  line-height: 1.1;
  color: #ffffff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  white-space: nowrap;
}

.status-online {
  background:
    linear-gradient(180deg, #fbfbfb 0%, #d7dce1 100%),
    linear-gradient(90deg, #35f332 0%, #21d321 100%);
  border-color: #bfc4ca;
  position: relative;
}

.status-online .status-orb {
  background: radial-gradient(circle at 35% 30%, #98ff8a 0%, #3eff2d 40%, #17be15 100%);
  box-shadow:
    inset 0 8px 12px rgba(255, 255, 255, 0.42),
    inset 0 -6px 10px rgba(0, 0, 0, 0.16),
    0 0 0 4px #bfc4ca,
    0 0 14px rgba(62, 255, 45, 0.45);
}

.status-offline {
  background:
    linear-gradient(180deg, #fbfbfb 0%, #d7dce1 100%),
    linear-gradient(90deg, #ff3838 0%, #df1111 100%);
  border-color: #bfc4ca;
  box-shadow:
    inset 0 2px 1px rgba(255, 255, 255, 0.95),
    inset 0 -2px 2px rgba(100, 116, 139, 0.22),
    0 8px 18px rgba(220, 38, 38, 0.2);
}

.status-offline .status-orb {
  background: radial-gradient(circle at 35% 30%, #ff9898 0%, #ff3636 42%, #c90d0d 100%);
  box-shadow:
    inset 0 8px 12px rgba(255, 255, 255, 0.4),
    inset 0 -6px 10px rgba(0, 0, 0, 0.18),
    0 0 0 4px #bfc4ca,
    0 0 14px rgba(255, 54, 54, 0.4);
}

.remote-status-pill::after {
  content: '';
  position: absolute;
  inset: 6px 8px 6px 30px;
  border-radius: 999px;
  z-index: 0;
}

.status-online::after {
  background: linear-gradient(180deg, #49ff43 0%, #23d723 55%, #20bb20 100%);
}

.status-offline::after {
  background: linear-gradient(180deg, #ff4d4d 0%, #f12020 55%, #cf1010 100%);
}

.status-orb,
.status-label,
.status-timer {
  position: relative;
  z-index: 1;
}

@media (max-width: 991.98px) {
  .remote-status-pill {
    width: 112px;
    min-width: 112px;
    max-width: 112px;
    padding-right: 9px;
    flex-basis: 112px;
  }

  .status-orb {
    width: 30px;
    height: 30px;
  }

  .status-label {
    font-size: 0.6rem;
  }
}
</style>

<style scoped>
.header-brand-text {
  letter-spacing: 1px;
}
.spinner {
  animation: spin 2s linear infinite;
}
@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
</style>
