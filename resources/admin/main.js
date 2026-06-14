import { createApp } from 'vue';
import App from './App.vue';
import './app.css';

const mount = document.getElementById('agentify-app');

if (mount) {
  // Data injected by wp_localize_script (Admin::bootstrap_data()).
  const boot = window.AgentifyData || {};
  createApp(App, { boot }).mount(mount);
}
