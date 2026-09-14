<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useTheme } from './composables/useTheme'
import AppSidebar from './components/layout/AppSidebar.vue'
import AppHeader from './components/layout/AppHeader.vue'
import AppFooter from './components/layout/AppFooter.vue'
import ModalReport from './components/modals/ModalReport.vue'
import ModalProfile from './components/modals/ModalProfile.vue'

const route = useRoute()
const { initTheme } = useTheme()

onMounted(() => {
  initTheme()
})
</script>

<template>
  <!-- Full-page / Blank Layout (e.g. Login page) -->
  <template v-if="route.meta.layout === 'blank'">
    <router-view />
  </template>

  <!-- Default Authenticated Layout with Sidebar, Header & Footer -->
  <template v-else>
    <div class="page">
      <AppSidebar />
      <AppHeader />
      <div class="page-wrapper">
        <router-view />
        <AppFooter />
      </div>
    </div>
    <ModalReport />
    <ModalProfile />
  </template>
</template>

<style>
/* Sembunyikan icon reveal bawaan browser (Edge/IE) agar konsisten menggunakan icon kustom di paling belakang */
input::-ms-reveal,
input::-ms-clear {
  display: none !important;
}
</style>
