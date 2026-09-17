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
/* Pastikan seluruh container mengambil tinggi penuh viewport agar footer selalu berada di bawah */
html,
body {
  height: 100%;
  min-height: 100vh;
}

#app {
  min-height: 100vh;
  height: 100%;
  display: flex;
  flex-direction: column;
}

.page {
  min-height: 100vh !important;
  display: flex !important;
  flex-direction: column !important;
  flex: 1 1 auto;
}

.page-wrapper {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 0 auto !important;
  min-height: calc(100vh - 3.5rem) !important;
}

/* Konten utama mengisi sisa ruang vertikal agar footer terdorong ke paling bawah */
.page-wrapper > :first-child:not(.footer),
.page-body {
  flex: 1 0 auto;
  margin-top: 0 !important;
}

/* Footer selalu menempel di bagian paling bawah */
.page-wrapper > footer,
footer.footer,
.footer {
  margin-top: auto !important;
  width: 100%;
}

/* Sembunyikan icon reveal bawaan browser (Edge/IE) agar konsisten menggunakan icon kustom di paling belakang */
input::-ms-reveal,
input::-ms-clear {
  display: none !important;
}
</style>
