<template>
  <div class="app-container">
    <header class="app-header">
      <div class="header-content">
        <h1>📦 Production Management System</h1>
        <nav class="main-nav">
          <router-link to="/orders" class="nav-link" active-class="active">Orders</router-link>
          <router-link to="/batches" class="nav-link" active-class="active">Batches</router-link>
        </nav>
      </div>
    </header>

    <main class="app-main">
      <router-view />
    </main>

    <footer class="app-footer">
      <p>&copy; 2026 Production Management System. All rights reserved.</p>
    </footer>
  </div>
</template>

<script>
export default {
  name: 'App'
}
</script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
  background-color: #f5f5f5;
  color: #333;
}

.app-container {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.app-header {
  background-color: #1976d2;
  color: white;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  sticky-top: 0;
  z-index: 100;
}

.header-content {
  max-width: 1400px;
  margin: 0 auto;
  padding: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.app-header h1 {
  font-size: 24px;
  margin: 0;
}

.main-nav {
  display: flex;
  gap: 20px;
}

.nav-link {
  color: white;
  text-decoration: none;
  font-weight: 500;
  padding: 8px 16px;
  border-radius: 4px;
  transition: background-color 0.3s;
}

.nav-link:hover {
  background-color: rgba(255, 255, 255, 0.2);
}

.nav-link.active {
  background-color: rgba(0, 0, 0, 0.2);
}

.app-main {
  flex: 1;
  max-width: 1400px;
  width: 100%;
  margin: 0 auto;
}

.app-footer {
  background-color: #f5f5f5;
  border-top: 1px solid #ddd;
  padding: 20px;
  text-align: center;
  color: #666;
  font-size: 14px;
}
</style>
