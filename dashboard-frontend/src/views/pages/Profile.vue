<script setup>
import { ref, onMounted } from 'vue'
import { useDashboardStore } from '@/stores/dashboard'
import {
  CButton,
  CFormInput,
  CFormLabel,
  CCard,
  CCardBody,
  CCardHeader,
  CAvatar,
} from '@coreui/vue'
import axios from 'axios'

const dashboard = useDashboardStore()
const user = ref({
  name: '',
  email: '',
  avatar: null,
})
const password = ref('')
const confirmPassword = ref('')
const selectedFile = ref(null)
const message = ref('')
const error = ref('')
const isLoading = ref(false)

// Init
onMounted(() => {
  if (dashboard.user) {
    user.value = { ...dashboard.user }
  }
  fetchProfile()
})

const getToken = () => localStorage.getItem('mnh_token')
const apiBase = import.meta.env.VITE_API_BASE_URL || '/api/v1'
const isDeletingAvatar = ref(false)

const fetchProfile = async () => {
  try {
    const res = await axios.get(`${apiBase}/profile`, {
      headers: { Authorization: `Bearer ${getToken()}` },
    })
    if (res.data.status === 'success') {
      user.value = res.data.data.user
    }
  } catch (e) {
    console.error('Fetch profile error', e)
  }
}

const handleFileChange = (event) => {
  selectedFile.value = event.target.files[0]
}

const updateProfile = async () => {
  message.value = ''
  error.value = ''
  isLoading.value = true

  const formData = new FormData()
  formData.append('name', user.value.name)
  formData.append('email', user.value.email)
  if (password.value) {
    formData.append('password', password.value)
    formData.append('password_confirmation', confirmPassword.value)
  }
  if (selectedFile.value) {
    formData.append('avatar', selectedFile.value)
  }
  formData.append('_method', 'PUT') // Handle PUT

  try {
    const res = await axios.post(`${apiBase}/profile`, formData, {
      headers: {
        Authorization: `Bearer ${getToken()}`,
      },
    })

    if (res.data.status === 'success') {
      message.value = 'Profile updated successfully!'
      user.value = res.data.data.user
      // Update store
      dashboard.user = res.data.data.user
      localStorage.setItem('mnh_user', JSON.stringify(res.data.data.user))
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to update profile'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

const deleteAvatar = async () => {
  if (!user.value.avatar) return
  isDeletingAvatar.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await axios.delete(`${apiBase}/profile/avatar`, {
      headers: { Authorization: `Bearer ${getToken()}` },
    })
    if (res.data.status === 'success') {
      user.value.avatar = null
      dashboard.user = { ...dashboard.user, avatar: null }
      localStorage.setItem('mnh_user', JSON.stringify(dashboard.user))
      message.value = 'Profile picture removed.'
    }
  } catch (e) {
    error.value = 'Failed to remove profile picture.'
  } finally {
    isDeletingAvatar.value = false
  }
}

const getAvatarUrl = (path) => {
  if (!path) return 'src/assets/images/avatars/8.jpg' // Default or handle asset
  if (path.startsWith('http')) return path
  const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || '/api/v1'
  const baseUrl = apiBaseUrl.startsWith('http')
    ? apiBaseUrl.replace(/\/api\/v1\/?$/, '')
    : window.location.origin
  return `${baseUrl}/${path.replace(/^\/+/, '')}`
}
</script>

<template>
  <CCard class="mb-4 shadow-sm">
    <CCardHeader class="bg-light fw-bold"> User Profile </CCardHeader>
    <CCardBody>
      <div v-if="message" class="alert alert-success">{{ message }}</div>
      <div v-if="error" class="alert alert-danger">{{ error }}</div>

      <div class="row">
        <div class="col-md-4 text-center mb-4">
          <div class="mb-3">
            <img
              :src="getAvatarUrl(user.avatar)"
              alt="Avatar"
              class="rounded-circle img-thumbnail"
              style="width: 150px; height: 150px; object-fit: cover"
            />
          </div>
          <div class="mb-3">
            <input
              type="file"
              @change="handleFileChange"
              class="form-control form-control-sm"
              accept="image/*"
            />
          </div>
          <div v-if="user.avatar" class="mb-3">
            <CButton
              color="danger"
              variant="outline"
              size="sm"
              :disabled="isDeletingAvatar"
              @click="deleteAvatar"
            >
              {{ isDeletingAvatar ? 'Removing...' : 'Remove Picture' }}
            </CButton>
          </div>
        </div>

        <div class="col-md-8">
          <form @submit.prevent="updateProfile">
            <div class="mb-3">
              <CFormLabel>Name</CFormLabel>
              <CFormInput v-model="user.name" required />
            </div>
            <div class="mb-3">
              <CFormLabel>Email</CFormLabel>
              <CFormInput type="email" v-model="user.email" required />
            </div>

            <hr class="my-4" />
            <h6 class="mb-3 text-muted">Change Password (Optional)</h6>

            <div class="row">
              <div class="col-md-6 mb-3">
                <CFormLabel>New Password</CFormLabel>
                <CFormInput type="password" v-model="password" minlength="8" />
              </div>
              <div class="col-md-6 mb-3">
                <CFormLabel>Confirm Password</CFormLabel>
                <CFormInput type="password" v-model="confirmPassword" minlength="8" />
              </div>
            </div>

            <CButton type="submit" color="primary" :disabled="isLoading">
              {{ isLoading ? 'Saving...' : 'Update Profile' }}
            </CButton>
          </form>
        </div>
      </div>
    </CCardBody>
  </CCard>
</template>
