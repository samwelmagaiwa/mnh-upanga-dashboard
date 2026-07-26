<script setup>
import { computed } from 'vue'
import { useDashboardStore } from '@/stores/dashboard'
import { useRouter } from 'vue-router'

const dashboard = useDashboardStore()
const router = useRouter()

const defaultAvatar =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='50' fill='%23e0e0e0'/%3E%3Ccircle cx='50' cy='38' r='18' fill='%23bdbdbd'/%3E%3Cellipse cx='50' cy='85' rx='28' ry='20' fill='%23bdbdbd'/%3E%3C/svg%3E"

const userAvatar = computed(() => {
  const path = dashboard.user?.avatar
  if (!path) return defaultAvatar
  if (path.startsWith('http')) return path
  const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || '/api/v1'
  const baseUrl = apiBaseUrl.startsWith('http')
    ? apiBaseUrl.replace(/\/api\/v1\/?$/, '')
    : window.location.origin
  return `${baseUrl}/${path.replace(/^\/+/, '')}`
})

const logout = () => {
  dashboard.logout()
}

const goToProfile = () => {
  router.push('/profile')
}
</script>

<template>
  <CDropdown placement="bottom-end" variant="nav-item">
    <CDropdownToggle class="py-0 pe-0" :caret="false">
      <CAvatar :src="userAvatar" size="md" status="success" class="premium-avatar" />
    </CDropdownToggle>
    <CDropdownMenu class="pt-0 premium-dropdown-menu">
      <CDropdownHeader
        component="h6"
        class="bg-body-secondary text-body-secondary fw-bold mb-2 rounded-top header-premium"
      >
        Account Settings
      </CDropdownHeader>

      <CDropdownItem @click="goToProfile" class="premium-item" style="cursor: pointer">
        <CIcon icon="cil-user" class="me-2 text-primary" /> Profile
      </CDropdownItem>

      <CDropdownDivider />

      <CDropdownItem @click="logout" class="premium-item text-danger" style="cursor: pointer">
        <CIcon icon="cil-account-logout" class="me-2 text-danger" /> Logout
      </CDropdownItem>
    </CDropdownMenu>
  </CDropdown>
</template>

<style scoped>
.premium-avatar {
  transition:
    transform 0.2s ease,
    box-shadow 0.2s ease;
  border: 2px solid rgba(255, 255, 255, 0.8);
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.premium-avatar:hover {
  transform: scale(1.05);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.premium-dropdown-menu {
  border: none;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
  border-radius: 12px;
  overflow: hidden;
  min-width: 200px;
  animation: slideIn 0.2s ease-out;
}

.header-premium {
  background: linear-gradient(to right, #f8f9fa, #e9ecef);
  padding: 12px 16px;
  font-size: 0.75rem;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}

.premium-item {
  padding: 10px 16px;
  transition: all 0.2s ease;
  font-weight: 500;
  display: flex;
  align-items: center;
}

.premium-item:hover {
  background-color: #f8f9fa;
  transform: translateX(5px);
}

.premium-item:active {
  background-color: #e9ecef;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
