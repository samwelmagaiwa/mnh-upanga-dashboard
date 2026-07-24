import { ref, computed, watch } from 'vue'
import { defineStore } from 'pinia'
import axios from 'axios'
import * as XLSX from 'xlsx'
import avatar1 from '@/assets/images/avatars/1.jpg'
import avatar2 from '@/assets/images/avatars/2.jpg'
import avatar3 from '@/assets/images/avatars/3.jpg'
import avatar4 from '@/assets/images/avatars/4.jpg'
import avatar5 from '@/assets/images/avatars/5.jpg'
import avatar6 from '@/assets/images/avatars/6.jpg'

export const useDashboardStore = defineStore('dashboard', () => {
  const getInitialWeek = () => {
    const d = new Date()
    const day = d.getDay()
    const diff = d.getDate() - day + (day === 0 ? -6 : 1)
    const start = new Date(d.setDate(diff))
    const end = new Date(start)
    end.setDate(start.getDate() + 6)
    return [start, end]
  }

  const selectedPeriod = ref('day')
  const selectedDay = ref(new Date())
  const selectedWeek = ref(getInitialWeek())
  const selectedMonth = ref(new Date())
  const selectedYear = ref(new Date())
  const selectedRange = ref([new Date(), new Date()])
  const searchTerm = ref('')

  const realStats = ref(null)
  const previousStats = ref(null)
  const compLabel = ref('')
  const pieStats = ref(null)
  const genderRadarData = ref(null)
  const compStats = ref(null)
  const serviceTrendData = ref(null) // null = loading
  const referralStats = ref(null) // null = loading, [] = no data, [items] = has data
  const realClinics = ref(null) // null = loading, [] = no data
  const detailedClinics = ref(null) // null = loading, [] = no data
  const topDiseases = ref(null) // null = loading, [] = no data
  const selectedClinic = ref('All Clinics')
  const selectedBusinessUnit = ref(null) // null = All BUs
  const availableBusinessUnits = ref([]) // fetched from API
  const isBUFilterLoading = ref(false)
  const diagnosisMetadata = ref({}) // code => {abbreviation, description}
  const isLoading = ref(false)
  const isTrendsLoading = ref(false)
  const isDetailedLoading = ref(false)
  const trendStatus = ref('starting')
  const trendDebug = ref('')
  const activeBatchId = ref(localStorage.getItem('mnh_active_batch') || null)
  const syncProgress = ref(0)
  const gaps = ref(null) // null = loading, [] = no data
  const gapsLoading = ref(false)
  const isRepairing = ref(false)
  const isSyncing = computed(() => !!activeBatchId.value || isRepairing.value)
  const user = ref(JSON.parse(localStorage.getItem('mnh_user')) || null)
  const token = ref(localStorage.getItem('mnh_token') || null)
  const isAuthenticated = computed(() => !!token.value)
  const dataVersion = ref(null)
  const lastUpdated = ref(null)
  const latestVisitId = ref(0)
  const todayCount = ref(0)
  const totalCount = ref(0)
  const pollInterval = ref(5000)
  const consecutiveNoChanges = ref(0)
  const futureDateWarning = ref(null)
  const breakdownMode = ref(false)
  const isSyncNeeded = ref(false)
  const lastSyncFinished = ref(false)
  const isSyncWaiting = ref(false)
  const isUpToDate = ref(false)
  const remoteApiAvailable = ref(null)
  const batchesAhead = ref(0)
  const syncMessage = ref('')
  const dismissedBatchId = ref(null)
  const isInitialized = ref(false) // Track if first load is complete
  
  // Offline resilience state
  const offlineTimerCountdown = ref(null) // Remaining seconds, null when not offline or expired
  const isUsingCachedData = ref(false) // True when showing cached data due to offline
  const offlineStartTime = ref(null) // When did API become unavailable
  const OFFLINE_GRACE_PERIOD_MS = 15 * 60 * 1000 // Total 15 minutes in milliseconds
  const OFFLINE_REPORT_DELAY_MS = 5 * 60 * 1000 // 5 minutes silent delay before UI shows 'OFFLINE'
  const isOfflineUIReported = ref(false) // Whether we should actually show the offline badge/timer
  let offlineCountdownInterval = null
  
  let pulseTimer = null
  let initialLoadComplete = false
  let lastSilentRefresh = 0
  const SILENT_REFRESH_COOLDOWN = 15000 // 15s minimum between silent refreshes

  const checkForUpdates = async () => {
    if (isLoading.value) return
    try {
      const res = await api.get('/dashboard/check-updates', {
        headers: { 'Cache-Control': 'no-cache' },
      })
      const previousRemoteApiAvailability = remoteApiAvailable.value
      const newVersion = res.data.version
      const newVisitId = res.data.latest_visit_id || 0
      const newTodayCount = res.data.today_count || 0
      const newTotalCount = res.data.total_count || 0

      if (typeof res.data.remote_api_available === 'boolean') {
        remoteApiAvailable.value = res.data.remote_api_available
      }

      const hasVersionChange = dataVersion.value && dataVersion.value !== newVersion
      const hasNewVisit = latestVisitId.value > 0 && newVisitId > latestVisitId.value
      const hasTodayCountChange = todayCount.value > 0 && newTodayCount !== todayCount.value
      const hasTotalCountChange = totalCount.value > 0 && newTotalCount !== totalCount.value

      const wentOffline = previousRemoteApiAvailability !== false && remoteApiAvailable.value === false

      if (hasVersionChange || hasNewVisit || hasTodayCountChange || hasTotalCountChange || wentOffline) {
        consecutiveNoChanges.value = 0
        pollInterval.value = 5000
        lastUpdated.value = new Date()
        // Silent refresh with cooldown — avoid hammering during background sync
        const now = Date.now()
        if (!activeBatchId.value && (now - lastSilentRefresh) > SILENT_REFRESH_COOLDOWN) {
          lastSilentRefresh = now
          fetchStats(true)
        }
      } else {
        consecutiveNoChanges.value++
        if (consecutiveNoChanges.value > 24) {
          pollInterval.value = Math.min(pollInterval.value + 1000, 15000)
        }
      }

      // Only speed up polling when user has an active tracked batch
      if (activeBatchId.value) {
        pollInterval.value = 3000
      }
      isSyncNeeded.value = res.data.sync_needed || false
      isUpToDate.value = res.data.is_up_to_date || false

      // Auto-latch to active sync batches (respect user dismissals)
      if (res.data.is_syncing && res.data.active_batch_id && !activeBatchId.value) {
        if (res.data.active_batch_id !== dismissedBatchId.value) {
          activeBatchId.value = res.data.active_batch_id
          localStorage.setItem('mnh_active_batch', res.data.active_batch_id)
          pollBatchStatus()
        }
      }

      dataVersion.value = newVersion
      latestVisitId.value = newVisitId
      todayCount.value = newTodayCount
      totalCount.value = newTotalCount

      if (previousRemoteApiAvailability === false && remoteApiAvailable.value === true) {
        console.log('[DashboardStore] API is back online, clearing offline state')
        stopOfflineCountdown()
        const now = Date.now()
        if (!activeBatchId.value && now - lastSilentRefresh > SILENT_REFRESH_COOLDOWN) {
          lastSilentRefresh = now
          fetchStats(true)
        }
      }
    } catch (e) {
      if (remoteApiAvailable.value !== false) {
        console.warn('[DashboardStore] API connection lost. Entering 5-minute silent window.')
        remoteApiAvailable.value = false
        // Immediately start countdown to track the 5-minute window
        startOfflineCountdown()
        
        // Use cached data immediately if available to prevent blank screens
        if (realStats.value) {
          isUsingCachedData.value = true
        }
        fetchStats(true)
      }
      pollInterval.value = Math.min(pollInterval.value + 2000, 30000)
    }
  }

  const startPulse = () => {
    if (pulseTimer) clearTimeout(pulseTimer)
    const pulse = async () => {
      await checkForUpdates()
      pulseTimer = setTimeout(pulse, pollInterval.value)
    }
    pulse()
  }

  const stopPulse = () => {
    if (pulseTimer) {
      clearTimeout(pulseTimer)
      pulseTimer = null
    }
    stopOfflineCountdown()
  }

  const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  })

  api.interceptors.request.use((config) => {
    if (token.value) {
      config.headers.Authorization = `Bearer ${token.value}`
    }
    return config
  })

  api.interceptors.response.use(
    (response) => response,
    (error) => {
      if (error.response?.status === 401) {
        logout()
      }
      return Promise.reject(error)
    },
  )

  const formatDate = (date) => {
    if (!date) return null
    try {
      const d = new Date(date)
      if (isNaN(d.getTime())) return null
      const year = d.getFullYear()
      const month = String(d.getMonth() + 1).padStart(2, '0')
      const day = String(d.getDate()).padStart(2, '0')
      return `${year}-${month}-${day}`
    } catch (e) {
      return null
    }
  }

  // Offline resilience cache functions
  const generateCacheKey = (startDate, endDate) => {
    return `mnh_dashboard_cache_${startDate}_${endDate}`
  }

  const saveDataToCache = (startDate, endDate, data) => {
    try {
      const cacheKey = generateCacheKey(startDate, endDate)
      const cacheData = {
        timestamp: Date.now(),
        startDate,
        endDate,
        stats: data.stats,
        previousStats: data.previousStats,
        clinics: data.clinics,
        pie: data.pie,
        comparison: data.comparison,
        referrals: data.referrals,
        trends: data.trends,
        compLabel: data.compLabel,
        remoteApiAvailable: data.remoteApiAvailable,
      }
      localStorage.setItem(cacheKey, JSON.stringify(cacheData))
      console.log('[DashboardStore] Data cached for', startDate, '-', endDate)
    } catch (e) {
      console.error('[DashboardStore] Error saving to cache:', e)
    }
  }

  const getCachedData = (startDate, endDate) => {
    try {
      const cacheKey = generateCacheKey(startDate, endDate)
      const cached = localStorage.getItem(cacheKey)
      if (!cached) {
        // Fallback: If exact range not found, look for ANY cached dashboard data
        console.log('[DashboardStore] Exact cache not found, searching for any cached data...')
        const anyCachedData = findAnyCachedData()
        if (anyCachedData) {
          console.log('[DashboardStore] Found fallback cached data from different date range')
          return anyCachedData
        }
        return null
      }
      const data = JSON.parse(cached)
      const age = Date.now() - data.timestamp
      console.log('[DashboardStore] Found cached data for', startDate, '-', endDate, 'age:', age)
      return data
    } catch (e) {
      console.error('[DashboardStore] Error reading from cache:', e)
      return null
    }
  }

  const findAnyCachedData = () => {
    try {
      const dashboardPrefix = 'dashboard_cache_'
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i)
        if (key && key.startsWith(dashboardPrefix)) {
          const cached = localStorage.getItem(key)
          if (cached) {
            const data = JSON.parse(cached)
            // Only return if it has essential data
            if (data.stats && data.clinics) {
              return data
            }
          }
        }
      }
      return null
    } catch (e) {
      console.error('[DashboardStore] Error searching cache:', e)
      return null
    }
  }

  const startOfflineCountdown = () => {
    if (offlineCountdownInterval) clearInterval(offlineCountdownInterval)
    
    // Only set start time if not already in an offline session
    if (!offlineStartTime.value) {
      offlineStartTime.value = Date.now()
    }
    
    offlineCountdownInterval = setInterval(() => {
      if (!offlineStartTime.value) {
        if (offlineCountdownInterval) clearInterval(offlineCountdownInterval)
        return
      }

      const elapsed = Date.now() - offlineStartTime.value
      const remaining = Math.max(0, OFFLINE_GRACE_PERIOD_MS - elapsed)
      const seconds = Math.ceil(remaining / 1000)

      // Only report offline to UI (badge/timer) after the 5-minute silent delay
      if (elapsed >= OFFLINE_REPORT_DELAY_MS) {
        isOfflineUIReported.value = true
      } else {
        isOfflineUIReported.value = false
      }

      if (remaining <= 0) {
        offlineTimerCountdown.value = null
        isUsingCachedData.value = false
        isOfflineUIReported.value = false
        offlineStartTime.value = null
        if (offlineCountdownInterval) clearInterval(offlineCountdownInterval)
        console.log('[DashboardStore] Offline grace period expired')
      } else {
        offlineTimerCountdown.value = seconds
      }
    }, 1000)
  }

  const stopOfflineCountdown = () => {
    if (offlineCountdownInterval) clearInterval(offlineCountdownInterval)
    offlineTimerCountdown.value = null
    isUsingCachedData.value = false
    isOfflineUIReported.value = false
    offlineStartTime.value = null
  }

  const calculateDateRange = () => {
    let start_date = ''
    let end_date = ''

    switch (selectedPeriod.value) {
      case 'day':
        start_date = formatDate(selectedDay.value || new Date())
        end_date = start_date
        break
      case 'week':
        const wVal = selectedWeek.value
        let wStart, wEnd
        if (Array.isArray(wVal) && wVal.length === 2) {
          ;[wStart, wEnd] = wVal
        } else if (wVal) {
          const d = new Date(wVal)
          const day = d.getDay()
          const diff = d.getDate() - day + (day === 0 ? -6 : 1)
          wStart = new Date(d.setDate(diff))
          wEnd = new Date(wStart)
          wEnd.setDate(wStart.getDate() + 6)
        }
        start_date = formatDate(wStart)
        end_date = formatDate(wEnd)
        break
      case 'month':
        const mVal = selectedMonth.value
        let mDate
        if (mVal && typeof mVal === 'object' && 'month' in mVal) {
          mDate = new Date(mVal.year, mVal.month, 1)
        } else {
          mDate = new Date(mVal || new Date())
        }
        const mY = mDate.getFullYear()
        const mM = mDate.getMonth()
        start_date = formatDate(new Date(mY, mM, 1))
        end_date = formatDate(new Date(mY, mM + 1, 0))
        break
      case 'year':
        const yVal = selectedYear.value
        let yY
        if (yVal && typeof yVal === 'object' && 'year' in yVal) {
          yY = yVal.year
        } else if (yVal instanceof Date) {
          yY = yVal.getFullYear()
        } else {
          yY = yVal || new Date().getFullYear()
        }
        start_date = `${yY}-01-01`
        end_date = `${yY}-12-31`
        break
      case 'range':
        const rVal = selectedRange.value
        if (Array.isArray(rVal) && rVal.length === 2 && rVal[0] && rVal[1]) {
          start_date = formatDate(rVal[0])
          end_date = formatDate(rVal[1])
        }
        break
    }
    return { start_date, end_date }
  }

  const isFutureDate = (dateStr) => {
    if (!dateStr) return false
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const checkDate = new Date(dateStr)
    checkDate.setHours(0, 0, 0, 0)
    return checkDate > today
  }

  const isTodaySelected = computed(() => {
    const { start_date, end_date } = calculateDateRange()
    const todayStr = formatDate(new Date())
    return start_date === todayStr && end_date === todayStr
  })

  const clearSyncStatus = () => {
    if (activeBatchId.value) {
      dismissedBatchId.value = activeBatchId.value
    }
    activeBatchId.value = null
    isRepairing.value = false
    syncProgress.value = 0
    syncMessage.value = ''
    isSyncWaiting.value = false
    localStorage.removeItem('mnh_active_batch')
  }

  const cancelSync = async () => {
    const batchId = activeBatchId.value
    if (batchId) {
      try {
        await api.post(`/sync/cancel-batch/${batchId}`)
        console.log('[Dashboard] Sync cancelled:', batchId)
      } catch (e) {
        console.error('[Dashboard] Failed to cancel batch:', e)
      }
    }
    clearSyncStatus()
  }

  let fetchInProgress = false
  const fetchStats = async (silent = false) => {
    // Prevent concurrent fetches
    if (fetchInProgress) return
    fetchInProgress = true
    
    if (!silent) {
      isLoading.value = true
      isTrendsLoading.value = true
    }
    try {
      const { start_date, end_date } = calculateDateRange()

      if (isFutureDate(start_date) || isFutureDate(end_date)) {
        futureDateWarning.value = {
          title: 'Future Date Selected',
          message: 'Data for future dates is not yet available.',
          type: 'warning',
        }
        realStats.value = null
        previousStats.value = null
        compLabel.value = ''
        realClinics.value = null
        pieStats.value = null
        compStats.value = null
        referralStats.value = null
        serviceTrendData.value = null
        selectedBusinessUnit.value = null
        availableBusinessUnits.value = []
        stopOfflineCountdown()
        return
      }

      futureDateWarning.value = null

      if (start_date && end_date) {
        const timestamp = Date.now()
        const isBackgroundSyncRefresh = silent && !!activeBatchId.value
        if (!silent) {
          isDetailedLoading.value = true
          detailedClinics.value = null
        }

        const period = selectedPeriod.value === 'range' ? 'range' : selectedPeriod.value
        const breakdownParam = breakdownMode.value ? '&breakdown=monthly' : ''
        // Always use fresh=1 to avoid stale cache issues with referral and other data
        const freshParam = '&fresh=1'

        const { data } = await api.get(
          `/dashboard/snapshot?start_date=${start_date}&end_date=${end_date}&period=${period}${breakdownParam}${freshParam}&_t=${timestamp}`,
        )

        // Check if remote API is available
        const isRemoteApiAvailable = data?.stats?.meta?.remote_api_available !== false
        
        if (typeof data?.stats?.meta?.remote_api_available === 'boolean') {
          remoteApiAvailable.value = data.stats.meta.remote_api_available
        }

        // If remote API is down, use cache instead of showing stale backend data
        if (!isRemoteApiAvailable) {
          console.log('[DashboardStore] Remote API unavailable')
          const hasDbStats = data?.stats?.stats && data?.stats?.stats?.total_visits > 0
          
          if (!hasDbStats) {
            console.log('[DashboardStore] No database stats available, attempting local cache...')
            const cachedData = getCachedData(start_date, end_date)
            
            if (cachedData) {
              console.log('[DashboardStore] Cache found, restoring cached data')
              realStats.value = cachedData.stats
              previousStats.value = cachedData.previousStats
              compLabel.value = cachedData.compLabel
              realClinics.value = cachedData.clinics
              pieStats.value = cachedData.pie
              compStats.value = cachedData.comparison
              referralStats.value = cachedData.referrals
              serviceTrendData.value = cachedData.trends || { labels: [], datasets: [] }
              
              // Mark that we're using cached data and start countdown
              isUsingCachedData.value = true
              startOfflineCountdown()
              trendStatus.value = 'cached'
              
              lastUpdated.value = new Date().toLocaleTimeString()
              if (!isInitialized.value) {
                isInitialized.value = true
              }
              if (!initialLoadComplete) {
                initialLoadComplete = true
                setTimeout(() => startPulse(), 2000)
              }
              return
            } else {
              console.log('[DashboardStore] No cache or DB stats available, showing empty state')
              trendStatus.value = 'error'
              isUsingCachedData.value = false
              stopOfflineCountdown()
              return
            }
          } else {
            console.log('[DashboardStore] Database has synced stats. Using DB stats as fallback.')
            startOfflineCountdown()
          }
        }

        // API is available, update with fresh data
        realStats.value = data.stats.stats
        previousStats.value = data.stats.previous_stats
        compLabel.value = data.stats.compLabel

        realClinics.value = data.clinics
        pieStats.value = data.pie
        if (!isBackgroundSyncRefresh) {
          fetchGenderRadarStats(silent)
          fetchDetailedClinics(silent)
          fetchBusinessUnits()
        } else if ((detailedClinics.value?.length || 0) === 0 || (data.stats.stats?.total_detailed || 0) > 0) {
          fetchDetailedClinics(true, { background: true })
        }
        compStats.value = data.comparison
        referralStats.value = data.referrals
        fetchTopDiseases(silent)

        if (data.trends && Array.isArray(data.trends.labels)) {
          serviceTrendData.value = data.trends
          trendStatus.value = 'success'
        } else {
          serviceTrendData.value = { labels: [], datasets: [] }
          trendStatus.value = 'empty'
        }

        // Re-attach to active sync batch if not already polling
        if (data.stats.meta && data.stats.meta.active_batch_id && !activeBatchId.value) {
          console.log(
            '[DashboardStore] Re-attaching to active sync:',
            data.stats.meta.active_batch_id,
          )
          activeBatchId.value = data.stats.meta.active_batch_id
          pollBatchStatus()
        }

        lastUpdated.value = new Date().toLocaleTimeString()

        // Save fetched data to cache for offline resilience
        saveDataToCache(start_date, end_date, {
          stats: data.stats.stats,
          previousStats: data.stats.previous_stats,
          clinics: data.clinics,
          pie: data.pie,
          comparison: data.comparison,
          referrals: data.referrals,
          trends: data.trends,
          compLabel: data.stats.compLabel,
          remoteApiAvailable: remoteApiAvailable.value,
        })

        // Clear offline state on successful fetch
        stopOfflineCountdown()

        // Mark as initialized after first successful fetch
        if (!isInitialized.value) {
          isInitialized.value = true
        }

        if (!initialLoadComplete) {
          initialLoadComplete = true
          setTimeout(() => startPulse(), 2000)
        }
      }
    } catch (error) {
      console.error('[DashboardStore] Error fetching snapshot:', error)
      
      // Mark API as unavailable on network errors
      if (error.response?.status >= 500 || !error.response) {
        const wasAvailable = remoteApiAvailable.value !== false
        remoteApiAvailable.value = false
        
        // If we have data in memory, enter grace period immediately to avoid UI flicker
        if (wasAvailable && realStats.value) {
          isUsingCachedData.value = true
          startOfflineCountdown()
        }
      }
      
      // Offline resilience: Try to restore from cache on API failure
      const { start_date, end_date } = calculateDateRange()
      const cachedData = getCachedData(start_date, end_date)

      if (cachedData) {
        console.log('[DashboardStore] Restoring data from cache due to API failure')
        // Restore all cached data
        realStats.value = cachedData.stats
        previousStats.value = cachedData.previousStats
        compLabel.value = cachedData.compLabel
        realClinics.value = cachedData.clinics
        pieStats.value = cachedData.pie
        compStats.value = cachedData.comparison
        referralStats.value = cachedData.referrals
        serviceTrendData.value = cachedData.trends || { labels: [], datasets: [] }
        
        // Mark that we're using cached data and start countdown
        isUsingCachedData.value = true
        startOfflineCountdown()
        trendStatus.value = 'cached'
      } else if (realStats.value) {
        // Even if disk cache failed, if we have data in memory, STAY in grace period
        console.log('[DashboardStore] API failed, but keeping in-memory data active')
        isUsingCachedData.value = true
        startOfflineCountdown()
        trendStatus.value = 'cached'
      } else {
        // Truly no data anywhere
        trendStatus.value = 'error'
        isUsingCachedData.value = false
        stopOfflineCountdown()
      }
    } finally {
      if (!silent) isLoading.value = false
      isTrendsLoading.value = false
      fetchInProgress = false
    }
  }

  const triggerSync = async (date = null) => {
    try {
      const targetDate = date || formatDate(selectedDay.value)
      const { data } = await api.get(`/sync/trigger/${targetDate}`)
      return data
    } catch (error) {
      console.error('[DashboardStore] Error triggering sync:', error)
      throw error
    }
  }

  let fetchDebounceTimer = null
  const scheduleFetchStats = () => {
    if (fetchDebounceTimer) clearTimeout(fetchDebounceTimer)
    fetchDebounceTimer = setTimeout(() => {
      fetchStats()
    }, 250)
  }

  const login = async (credentials) => {
    try {
      const response = await api.post('/login', credentials)
      if (response.data.status === 'success') {
        const { user: userData, token: tokenData } = response.data.data
        user.value = userData
        token.value = tokenData
        localStorage.setItem('mnh_user', JSON.stringify(userData))
        localStorage.setItem('mnh_token', tokenData)
        return { success: true, user: userData }
      }
    } catch (error) {
      return { success: false, message: 'Invalid credentials' }
    }
  }

  const logout = async () => {
    try {
      if (token.value) await api.post('/logout')
    } catch (e) {
    } finally {
      user.value = null
      token.value = null
      localStorage.removeItem('mnh_user')
      localStorage.removeItem('mnh_token')
      window.location.href = '/#/login'
    }
  }

  let lastRefreshedProgress = 0
  let lastRefreshedProcessedJobs = 0

  const pollBatchStatus = async () => {
    if (!activeBatchId.value) return
    try {
      const res = await api.get(`/sync/batch/${activeBatchId.value}`)
      const prevProgress = syncProgress.value
      const processedJobs = res.data.processed_jobs || 0
      syncProgress.value = res.data.progress
      isSyncWaiting.value = res.data.is_waiting || false
      syncMessage.value = res.data.message || ''

      if (res.data.finished || res.data.cancelled || res.data.progress >= 100) {
        // Sync complete — final refresh and cleanup
        activeBatchId.value = null
        localStorage.removeItem('mnh_active_batch')
        syncProgress.value = 0
        syncMessage.value = ''
        isSyncWaiting.value = false
        fetchStats(true) // Final refresh with latest data
        lastRefreshedProgress = 0
        lastRefreshedProcessedJobs = 0
      } else {
        // Refresh often enough to surface partial synced data.
        const progressDelta = res.data.progress - lastRefreshedProgress
        const processedDelta = processedJobs - lastRefreshedProcessedJobs
        if (
          processedDelta >= 5 ||
          progressDelta >= 3 ||
          (prevProgress === 0 && res.data.progress > 0)
        ) {
          lastRefreshedProgress = res.data.progress
          lastRefreshedProcessedJobs = processedJobs
          fetchStats(true)
        }
        setTimeout(pollBatchStatus, 2000)
      }
    } catch (e) {
      activeBatchId.value = null
      localStorage.removeItem('mnh_active_batch')
      syncProgress.value = 0
      syncMessage.value = ''
    }
  }

  watch(activeBatchId, (newId) => {
    if (newId) localStorage.setItem('mnh_active_batch', newId)
    else localStorage.removeItem('mnh_active_batch')
  })

  if (activeBatchId.value) pollBatchStatus()

  const syncCurrentRange = async () => {
    if (isSyncing.value) return { ok: false, message: 'Sync in progress' }
    try {
      const { start_date, end_date } = calculateDateRange()
      if (!start_date || !end_date) return { ok: false }
      // Reset dismissed batch so new manual sync is always tracked
      dismissedBatchId.value = null
      lastRefreshedProgress = 0
      lastRefreshedProcessedJobs = 0
      const enqueueRes = await api.get(`/sync/enqueue/range?start_date=${start_date}&end_date=${end_date}`)
      activeBatchId.value = enqueueRes.data.batch_id
      syncProgress.value = 0
      if (enqueueRes.data.batch_id) {
        fetchStats(true)
        pollBatchStatus()
      }
      return {
        ok: true,
        batch_id: activeBatchId.value,
        message: enqueueRes.data.message || 'Background sync enqueued',
      }
    } catch (error) {
      throw error
    }
  }

  const fetchGenderRadarStats = async (silent = false) => {
    try {
      const { start_date, end_date } = calculateDateRange()
      const { data } = await api.get('/dashboard/gender-radar', {
        params: { start_date, end_date, _t: Date.now() },
      })
      genderRadarData.value = data
    } catch (error) {
      console.error('[DashboardStore] Error fetching gender radar:', error)
    }
  }

  const fetchPendingPatients = async () => {
    const { start_date, end_date } = calculateDateRange()
    try {
      const response = await api.get('/dashboard/pending-patients', {
        params: { start_date, end_date, _t: Date.now() },
      })
      return response.data.data || []
    } catch (error) {
      return []
    }
  }

  const fetchDuplicateVisits = async () => {
    const { start_date, end_date } = calculateDateRange()
    try {
      const response = await api.get('/dashboard/duplicated-data', {
        params: { start_date, end_date, _t: Date.now() },
      })
      return response.data.data || []
    } catch (error) {
      return []
    }
  }

  const fetchTopDiseases = async (silent = false) => {
    if (!silent) topDiseases.value = null
    try {
      const { start_date, end_date } = calculateDateRange()
      const { data } = await api.get('/dashboard/top-diseases', {
        params: {
          start_date,
          end_date,
          clinic_name: selectedClinic.value !== 'All Clinics' ? selectedClinic.value : undefined,
          _t: Date.now(),
        },
      })
      topDiseases.value = data
    } catch (error) {
      console.error('[DashboardStore] Error fetching top diseases:', error)
      topDiseases.value = []
    }
  }

  const fetchDetailedClinics = async (silent = false, options = {}) => {
    const { start_date, end_date } = calculateDateRange()
    const backgroundMode = options.background === true
    if (!silent) isDetailedLoading.value = true
    try {
      // Always fetch fresh data for detailed clinics to avoid stale cache issues
      // This ensures the table always shows current data matching the stats
      const response = await api.get('/dashboard/detailed-clinics', {
        params: { start_date, end_date, per_page: 500, page: 1, fresh: 1, _t: Date.now() },
      })
      const firstPageData = response.data.data || []
      const pagination = response.data.pagination

      // Set the first page immediately so the UI shows data
      detailedClinics.value = firstPageData
      if (response.data.diagnosis_metadata) {
        diagnosisMetadata.value = { ...diagnosisMetadata.value, ...response.data.diagnosis_metadata }
      }

      if (backgroundMode) {
        return
      }

      // Load more pages in background, but cap at 10 pages (5,000 rows) for table display
      // Summary badges use backend snapshot counts, so they are always accurate
      if (pagination && pagination.last_page > 1) {
        const maxPages = Math.min(pagination.last_page, 10)
        const allData = [...firstPageData]
        for (let page = 2; page <= maxPages; page++) {
          try {
            const pageResponse = await api.get('/dashboard/detailed-clinics', {
              params: { start_date, end_date, per_page: 500, page, fresh: 1, _t: Date.now() },
            })
            const pageData = pageResponse.data.data || []
            allData.push(...pageData)
            detailedClinics.value = [...allData]
            if (pageResponse.data.diagnosis_metadata) { diagnosisMetadata.value = { ...diagnosisMetadata.value, ...pageResponse.data.diagnosis_metadata } }
          } catch (pageError) {
            console.error(`[DashboardStore] Error fetching page ${page}:`, pageError)
          }
        }
      }
    } catch (error) {
      console.error('[DashboardStore] Error fetching detailed clinics:', error)
      // Only clear if not silent - avoid flickering stale data away during update
      if (!silent) detailedClinics.value = null
    } finally {
      isDetailedLoading.value = false
    }
  }

  const fetchGaps = async () => {
    const { start_date, end_date } = calculateDateRange()
    gapsLoading.value = true
    try {
      const response = await api.get('/dashboard/gaps', {
        params: { start_date, end_date, _t: Date.now() },
      })
      gaps.value = response.data.gaps || []
    } catch (error) {
      console.error('[DashboardStore] Error fetching gaps:', error)
    } finally {
      gapsLoading.value = false
    }
  }

  const repairGaps = async (dates, force = false) => {
    if (!dates || dates.length === 0) return
    try {
      const res = await api.post('/sync/repair-gaps', { dates, force })
      activeBatchId.value = res.data.batch_id
      syncProgress.value = 0
      pollBatchStatus()
      return {
        ok: true,
        batch_id: activeBatchId.value,
        message: res.data.message || 'Gap repair enqueued',
        ignored_dates: res.data.ignored_dates || [],
      }
    } catch (error) {
      console.error('[DashboardStore] Error repairing gaps:', error)
      throw error
    }
  }

  const exportToExcel = async () => {
    const { start_date, end_date } = calculateDateRange()
    const wb = XLSX.utils.book_new()
    const titleStyle = { font: { bold: true, sz: 14 } }

    const rangeLabel =
      start_date === end_date ? `Date: ${start_date}` : `Period: ${start_date} to ${end_date}`

    // ── 1. Summary Stats Sheet ──────────────────────────────────────────────
    const summaryHeader = [
      ['MUHIMBILI NATIONAL HOSPITAL (MNH)'],
      ['DASHBOARD SUMMARY REPORT'],
      [rangeLabel.toUpperCase()],
      [], // Spacer
      ['METRIC', 'VALUE'],
    ]

    const summaryData = metrics.value.map((m) => [
      m.title.toUpperCase(),
      typeof m.value === 'number' ? m.value.toLocaleString() : m.value,
    ])

    const wsSummary = XLSX.utils.aoa_to_sheet([...summaryHeader, ...summaryData])
    wsSummary['!cols'] = [{ wch: 40 }, { wch: 20 }]
    XLSX.utils.book_append_sheet(wb, wsSummary, 'Summary Stats')

    // ── 2. Detailed Clinics Sheet (fetch ALL pages from API) ────────────────
    isDetailedLoading.value = true
    let allVisits = []
    try {
      // Fetch page 1 to get pagination info
      const firstPage = await api.get('/dashboard/detailed-clinics', {
        params: { start_date, end_date, per_page: 5000, export: 1, page: 1, _t: Date.now() },
      })
      allVisits = [...(firstPage.data.data || [])]
      const pagination = firstPage.data.pagination

      if (pagination && pagination.last_page > 1) {
        // Prepare list of remaining pages
        const remainingPages = []
        for (let p = 2; p <= pagination.last_page; p++) {
          remainingPages.push(p)
        }

        // Parallelize fetching in batches of 5 to maximize throughput without overloading the server
        const BATCH_SIZE = 5
        for (let i = 0; i < remainingPages.length; i += BATCH_SIZE) {
          const batch = remainingPages.slice(i, i + BATCH_SIZE)
          console.log(`[Export] Fetching batch of pages: ${batch.join(', ')}...`)

          const batchResults = await Promise.all(
            batch.map((page) =>
              api.get('/dashboard/detailed-clinics', {
                params: { start_date, end_date, per_page: 5000, export: 1, page, _t: Date.now() },
              }),
            ),
          )

          batchResults.forEach((res) => {
            if (res.data && res.data.data) {
              allVisits.push(...res.data.data)
            }
          })
        }
      }
    } catch (error) {
      console.error('[Export] Error fetching all detailed clinics:', error)
      // Fall back to whatever we already have in memory
      allVisits = detailedClinics.value || []
    } finally {
      isDetailedLoading.value = false
    }

    if (allVisits.length > 0) {
      const detailedHeader = [
        ['MUHIMBILI NATIONAL HOSPITAL (MNH)'],
        ['DETAILED CLINIC VISIT REPORT'],
        [rangeLabel.toUpperCase()],
        [], // Spacer
        [
          'MR NUMBER',
          'GENDER',
          'AGE',
          'TYPE',
          'DATE',
          'TIME',
          'CASHIER (BILL DOCTOR)',
          'ATTEND DOCTOR',
          'CLINIC NAME',
          'CLINIC CODE',
          'DIAGNOSIS',
          'MISMATCH',
        ],
      ]

      const detailedRows = allVisits.map((item) => [
        item.mr_number,
        item.gender || 'N/A',
        item.pat_age || 'N/A',
        item.visit_type === 'N' ? 'NEW' : item.visit_type === 'F' ? 'FOLLOW-UP' : item.visit_type,
        formatDate(item.visit_date),
        item.cons_time || 'N/A',
        (item.bill_doct_name || 'N/A').toUpperCase(),
        (item.cons_doctor_name || 'N/A').toUpperCase(),
        (item.clinic_name || 'N/A').toUpperCase(),
        item.clinic_code || 'N/A',
        (item.final_diag || item.prov_diag || 'NOT RECORDED').toUpperCase(),
        item.is_mismatch ? 'YES' : 'NO',
      ])

      const wsDetailed = XLSX.utils.aoa_to_sheet([...detailedHeader, ...detailedRows])
      wsDetailed['!cols'] = [
        { wch: 15 }, // MR Number
        { wch: 10 }, // Gender
        { wch: 10 }, // Age
        { wch: 15 }, // Type
        { wch: 15 }, // Date
        { wch: 12 }, // Time
        { wch: 30 }, // Cashier
        { wch: 30 }, // Attend Doctor
        { wch: 35 }, // Clinic Name
        { wch: 15 }, // Clinic Code
        { wch: 55 }, // Diagnosis
        { wch: 12 }, // Mismatch
      ]
      XLSX.utils.book_append_sheet(wb, wsDetailed, 'Detailed Clinics')
    }

    // ── 3. Referral Distribution Sheet ──────────────────────────────────────
    if (referralStats.value && referralStats.value.length > 0) {
      const referralHeader = [
        ['MUHIMBILI NATIONAL HOSPITAL (MNH)'],
        ['REFERRAL DISTRIBUTION REPORT'],
        [rangeLabel.toUpperCase()],
        [], // Spacer
        ['FACILITY CODE', 'FACILITY NAME', 'TOTAL VISITS'],
      ]

      const referralRows = referralStats.value.map((item) => [
        item.code || 'N/A',
        (item.name || 'N/A').toUpperCase(),
        item.count || 0,
      ])

      const wsReferrals = XLSX.utils.aoa_to_sheet([...referralHeader, ...referralRows])
      wsReferrals['!cols'] = [
        { wch: 18 }, // Code
        { wch: 45 }, // Facility
        { wch: 15 }, // Visits
      ]
      XLSX.utils.book_append_sheet(wb, wsReferrals, 'Referrals')
    }

    const fileName = `MNH_Dashboard_Report_${start_date}_to_${end_date}.xlsx`
    XLSX.writeFile(wb, fileName)
  }

  setInterval(
    () => {
      if (!isLoading.value && !isSyncing.value) fetchStats(true)
    },
    1000 * 60 * 3,
  )

  watch(
    [selectedPeriod, selectedDay, selectedWeek, selectedMonth, selectedYear, selectedRange],
    () => {
      scheduleFetchStats()
    },
    { deep: true, immediate: true },
  )

  const tableExample = ref([
    {
      avatar: { src: avatar1, status: 'success' },
      user: { name: 'Yiorgos Avraamu', new: true, registered: 'Jan 1, 2023' },
      country: { name: 'USA', flag: 'cif-us' },
      usage: { value: 50, period: 'Jun 11, 2023 - Jul 10, 2023', color: 'success' },
      payment: { name: 'Mastercard', icon: 'cib-cc-mastercard' },
      activity: '10 sec ago',
    },
    {
      avatar: { src: avatar2, status: 'danger' },
      user: { name: 'Avram Tarasios', new: false, registered: 'Jan 1, 2023' },
      country: { name: 'Brazil', flag: 'cif-br' },
      usage: { value: 22, period: 'Jun 11, 2023 - Jul 10, 2023', color: 'info' },
      payment: { name: 'Visa', icon: 'cib-cc-visa' },
      activity: '5 minutes ago',
    },
  ])

   const metrics = computed(() => {
     if (realStats.value) {
       const s = realStats.value
       const age = s.age_groups || {}
       return [
         { title: 'PUBLIC', value: (s.public || 0).toLocaleString() },
         { title: 'NHIF', value: (s.nhif_visits || 0).toLocaleString() },
         { title: 'IPPM - PRIVATE', value: (s.ippm_private || 0).toLocaleString() },
         { title: 'IPPM - CREDIT', value: (s.ippm_credit || 0).toLocaleString() },
         { title: 'COST SHARING', value: (s.cost_sharing || 0).toLocaleString() },
         { title: 'NSSF', value: (s.nssf || 0).toLocaleString() },
         { title: 'FOREIGNER', value: (s.foreigner || 0).toLocaleString() },
         { title: 'Total Visits', value: (s.total_visits || 0).toLocaleString() },
         { title: 'Total OPD Count', value: (s.total_patients || 0).toLocaleString() },
         { title: 'Total Emergency Count', value: (s.emergency_visits || 0).toLocaleString() },
         { title: 'Total Consulted Count', value: (s.consulted || 0).toLocaleString() },
         { title: isTodaySelected.value ? 'Await Consultation Count' : 'Not Consulted Count', value: (s.pending || 0).toLocaleString() },
         { title: 'New Visits Count', value: (s.new_visits || 0).toLocaleString() },
         { title: 'Followups Count', value: (s.followups || 0).toLocaleString() },
         { title: 'Neonate (0-28d)', value: (age.neonate || 0).toLocaleString() },
         { title: 'Infant (29d-1y)', value: (age.infant || 0).toLocaleString() },
         { title: 'Child (1-12y)', value: (age.child || 0).toLocaleString() },
         { title: 'Adolescent (13-17y)', value: (age.adolescent || 0).toLocaleString() },
         { title: 'Adult (18-59y)', value: (age.adult || 0).toLocaleString() },
         { title: 'Elderly (60y+)', value: (age.elderly || 0).toLocaleString() },
         { title: 'Duplicated Data Count', value: (s.duplicates || 0).toLocaleString() },
       ]
     }
     return []
   })

  const jumpDate = (direction) => {
    const delta = direction === 'next' ? 1 : -1
    switch (selectedPeriod.value) {
      case 'day':
        selectedDay.value = new Date(
          new Date(selectedDay.value).setDate(selectedDay.value.getDate() + delta),
        )
        break
      case 'week':
        const wBase = Array.isArray(selectedWeek.value) ? selectedWeek.value[0] : selectedWeek.value
        const newD = new Date(wBase)
        newD.setDate(newD.getDate() + delta * 7)
        const day = newD.getDay()
        const diff = newD.getDate() - day + (day === 0 ? -6 : 1)
        const mon = new Date(newD.setDate(diff))
        const sun = new Date(mon)
        sun.setDate(mon.getDate() + 6)
        selectedWeek.value = [mon, sun]
        break
      case 'month':
        const mBase = new Date(selectedMonth.value)
        selectedMonth.value = new Date(mBase.setMonth(mBase.getMonth() + delta))
        break
      case 'year':
        const yBase = new Date(selectedYear.value)
        selectedYear.value = new Date(yBase.setFullYear(yBase.getFullYear() + delta))
        break
    }
  }

  const resetToToday = () => {
    selectedDay.value = new Date()
    selectedWeek.value = getInitialWeek()
    selectedMonth.value = new Date()
    selectedYear.value = new Date()
    selectedRange.value = [new Date(), new Date()]
  }

  const fetchBusinessUnits = async () => {
    try {
      const { start_date, end_date } = calculateDateRange()
      const { data } = await api.get('/dashboard/business-units', {
        params: { start_date, end_date, _t: Date.now() },
      })
      availableBusinessUnits.value = data.data || []
    } catch {
      availableBusinessUnits.value = []
    }
  }

  const filterClinicsByBU = async (bu) => {
    selectedBusinessUnit.value = bu
    isBUFilterLoading.value = true
    try {
      const { start_date, end_date } = calculateDateRange()
      const params = { start_date, end_date, fresh: 1, _t: Date.now() }
      if (bu) params.hosp_bu = bu
      const { data } = await api.get('/dashboard/clinics', { params })
      realClinics.value = data
    } catch {
      // keep existing realClinics on error
    } finally {
      isBUFilterLoading.value = false
    }
  }

  return {
    selectedPeriod,
    selectedDay,
    selectedWeek,
    selectedMonth,
    selectedYear,
    selectedRange,
    searchTerm,
    isInitialized,
    realStats,
    previousStats,
    compLabel,
    pieStats,
    compStats,
    serviceTrendData,
    referralStats,
    realClinics,
    detailedClinics,
    topDiseases,
    selectedClinic,
    diagnosisMetadata,
    isLoading,
    isTrendsLoading,
    fetchStats,
    fetchTopDiseases,
    syncCurrentRange,
    isSyncing,
    syncProgress,
    user,
    isAuthenticated,
    login,
    logout,
    startPulse,
    stopPulse,
    futureDateWarning,
    breakdownMode,
    setBreakdownMode: (e) => {
      breakdownMode.value = e
      fetchStats(true)
    },
    metrics,
    jumpDate,
    resetToToday,
    fetchPendingPatients,
    fetchDuplicateVisits,
    fetchGenderRadarStats,
    fetchGaps,
    repairGaps,
    exportToExcel,
    gaps,
    gapsLoading,
    genderRadarData,
    fetchDetailedClinics,
    isDetailedLoading,
    lastUpdated,
    clearSyncStatus,
    cancelSync,
    triggerSync,
    isSyncNeeded,
    lastSyncFinished,
    isSyncWaiting,
    batchesAhead,
    syncMessage,
    isUpToDate,
    remoteApiAvailable,
    isTodaySelected,
    offlineTimerCountdown,
    isUsingCachedData,
    isOfflineUIReported,
    calculateDateRange,
    selectedBusinessUnit,
    availableBusinessUnits,
    isBUFilterLoading,
    fetchBusinessUnits,
    filterClinicsByBU,
  }
})
