<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useDashboardStore } from '@/stores/dashboard'
import muhilogo from '@/assets/images/muhilogo.jpg'

const router = useRouter()
const dashboard = useDashboardStore()

const email = ref('')
const password = ref('')
const isLoading = ref(false)
const errorMessage = ref('')

const handleLogin = async () => {
  isLoading.value = true
  errorMessage.value = ''

  const result = await dashboard.login({
    email: email.value,
    password: password.value,
  })

  if (result.success) {
    router.push('/dashboard')
  } else {
    errorMessage.value = result.message
    isLoading.value = false
  }
}
</script>

<template>
  <div class="login-container">
    <div class="login-background"></div>
    <div class="login-overlay"></div>

    <div class="login-card-wrapper">
      <div class="login-card">
        <div class="login-header">
          <div class="logo-wrapper">
            <img :src="muhilogo" alt="MNH Logo" class="brand-logo" />
          </div>
          <h2 class="hospital-name">MUHIMBILI NATIONAL HOSPITAL</h2>
          <h1 class="welcome-text">Health Information Dashboard</h1>
          <p class="subtitle">Secure access for clinical and administrative oversight</p>
        </div>

        <form @submit.prevent="handleLogin" class="login-form">
          <div v-if="errorMessage" class="alert alert-danger mb-4">
            <strong>Login Failed:</strong> {{ errorMessage }}
          </div>

          <div class="form-group mb-4">
            <label for="email" class="form-label">Email Address</label>
            <div class="input-wrapper">
              <CIcon icon="cil-user" class="input-icon" />
              <input
                id="email"
                type="email"
                v-model="email"
                placeholder="Enter your email address"
                required
                class="form-input"
              />
            </div>
          </div>

          <div class="form-group mb-4">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label for="password" class="form-label mb-0">Password</label>
              <a href="#" class="forgot-link">Forgot password?</a>
            </div>
            <div class="input-wrapper">
              <CIcon icon="cil-lock-locked" class="input-icon" />
              <input
                id="password"
                type="password"
                v-model="password"
                placeholder="Enter your password"
                required
                class="form-input"
              />
            </div>
          </div>

          <div class="form-group mb-5">
            <label class="checkbox-wrapper">
              <input type="checkbox" />
              <span class="checkmark"></span>
              <span class="checkbox-label">Keep me signed in</span>
            </label>
          </div>

          <button type="submit" class="submit-btn" :disabled="isLoading">
            <template v-if="!isLoading">
              <span>Sign In to Dashboard</span>
              <CIcon icon="cil-arrow-right" class="ms-2 luxury-arrow" />
            </template>
            <div v-else class="luxury-loader"></div>
          </button>
        </form>

        <div class="login-footer">
          <p>
            &copy; {{ new Date().getFullYear() }} Muhimbili National Hospital. All rights reserved.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

.login-container {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  font-family: 'Inter', sans-serif;
  background-color: #000;
}

.login-background {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: url('@/assets/images/login-bg.png') no-repeat center center;
  background-size: cover;
  filter: brightness(0.4) saturate(1.2);
  z-index: 1;
}

.input-icon {
  position: absolute;
  left: 1.25rem;
  top: 50%;
  transform: translateY(-50%);
  color: rgba(255, 255, 255, 0.4);
  width: 1.1rem;
  height: 1.1rem;
}

.luxury-arrow {
  width: 1.2rem;
  height: 1.2rem;
  transition: transform 0.3s ease;
}

.submit-btn:hover .luxury-arrow {
  transform: translateX(5px);
}

.login-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: radial-gradient(
    circle at center,
    rgba(0, 48, 130, 0.2) 0%,
    rgba(0, 127, 62, 0.4) 100%
  );
  z-index: 2;
}

.login-card-wrapper {
  position: relative;
  z-index: 10;
  width: 100%;
  max-width: 480px;
  padding: 20px;
  animation: fadeInDown 0.8s ease-out;
}

.login-card {
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(20px) saturate(180%);
  -webkit-backdrop-filter: blur(20px) saturate(180%);
  border-radius: 24px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  padding: 3rem 2.5rem;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.login-header {
  text-align: center;
  margin-bottom: 2.5rem;
}

.logo-wrapper {
  width: 100px;
  height: 100px;
  background: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.5rem;
  padding: 10px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
  border: 4px solid rgba(0, 127, 62, 0.3);
}

.brand-logo {
  max-width: 100%;
  height: auto;
  border-radius: 50%;
}

.hospital-name {
  color: #fcd116; /* MNH Yellow */
  font-size: 0.9rem;
  font-weight: 700;
  letter-spacing: 2px;
  margin-bottom: 0.5rem;
  text-transform: uppercase;
}

.welcome-text {
  color: white;
  font-size: 1.75rem;
  font-weight: 700;
  margin-bottom: 0.75rem;
}

.subtitle {
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.95rem;
  line-height: 1.5;
}

.form-label {
  color: white;
  font-weight: 500;
  font-size: 0.9rem;
  margin-bottom: 0.5rem;
  display: block;
}

.input-wrapper {
  position: relative;
}

.form-input {
  width: 100%;
  padding: 1rem 1.25rem 1rem 3.25rem;
  background: rgba(0, 0, 0, 0.3);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  color: white;
  font-size: 1rem;
  transition: all 0.3s ease;
  outline: none;
}

.form-input::placeholder {
  color: rgba(255, 255, 255, 0.4);
}

.form-input:focus {
  background: rgba(0, 0, 0, 0.5);
  border-color: #007f3e;
  box-shadow: 0 0 0 4px rgba(0, 127, 62, 0.2);
  transform: translateY(-1px);
}

.forgot-link {
  color: #fcd116;
  text-decoration: none;
  font-size: 0.85rem;
  font-weight: 500;
  transition: opacity 0.2s;
}

.forgot-link:hover {
  opacity: 0.8;
}

.checkbox-wrapper {
  display: flex;
  align-items: center;
  cursor: pointer;
  color: #ffffff;
  font-size: 0.95rem;
  font-weight: 500;
  user-select: none;
  transition: opacity 0.2s;
}

.checkbox-wrapper:hover {
  opacity: 0.9;
}

.checkbox-wrapper input {
  display: none;
}

.checkmark {
  width: 22px;
  height: 22px;
  border: 2px solid rgba(255, 255, 255, 0.5);
  border-radius: 6px;
  margin-right: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  background: rgba(0, 0, 0, 0.4);
}

.checkbox-wrapper input:checked + .checkmark {
  background: #007f3e;
  border-color: #007f3e;
  box-shadow: 0 0 10px rgba(0, 127, 62, 0.5);
}

.checkbox-wrapper input:checked + .checkmark::after {
  content: '✓';
  color: white;
  font-size: 14px;
  font-weight: bold;
}

.submit-btn {
  width: 100%;
  padding: 1.15rem;
  background: linear-gradient(135deg, #007f3e 0%, #005c3e 100%);
  color: white;
  border: none;
  border-radius: 14px;
  font-size: 1.1rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10px 20px rgba(0, 127, 62, 0.3);
}

.submit-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, #00994d 0%, #007f3e 100%);
  transform: translateY(-2px);
  box-shadow: 0 15px 25px rgba(0, 127, 62, 0.4);
}

.submit-btn:active:not(:disabled) {
  transform: translateY(0);
}

.submit-btn:disabled {
  opacity: 0.7;
  cursor: wait;
}

.luxury-loader {
  width: 24px;
  height: 24px;
  border: 3px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: white;
  animation: luxury-spin 1s linear infinite;
}

@keyframes luxury-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes fadeInDown {
  from {
    opacity: 0;
    transform: translateY(-30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.login-footer {
  margin-top: 2.5rem;
  text-align: center;
  color: rgba(255, 255, 255, 0.4);
  font-size: 0.8rem;
}

.alert {
  padding: 1rem;
  border-radius: 12px;
  border: 1px solid transparent;
  font-size: 0.95rem;
}

.alert-danger {
  background: rgba(220, 53, 69, 0.15);
  border-color: rgba(220, 53, 69, 0.3);
  color: #ff6b6b;
}

.alert strong {
  font-weight: 600;
  margin-right: 0.5rem;
}

@media (max-width: 480px) {
  .login-card {
    padding: 2.5rem 1.5rem;
  }

  .welcome-text {
    font-size: 1.5rem;
  }
}
</style>
