<script setup>
import {
  ArcElement,
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  Title,
  Tooltip,
} from 'chart.js';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Doughnut, Line } from 'vue-chartjs';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
);

const props = defineProps({
  agent: { type: Object, required: true },
});

const maxPoints = 20;
const labels = ref([]);
const cpuSeries = ref([]);
const memorySeries = ref([]);

const chartText = 'rgb(148, 163, 184)';
const chartGrid = 'rgba(148, 163, 184, 0.15)';
const cpuColor = 'rgb(59, 130, 246)';
const memoryColor = 'rgb(16, 185, 129)';

const baseChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      labels: { color: chartText },
    },
  },
  scales: {
    x: {
      ticks: { color: chartText, maxRotation: 0, autoSkip: true, maxTicksLimit: 6 },
      grid: { color: chartGrid },
    },
    y: {
      min: 0,
      max: 100,
      ticks: {
        color: chartText,
        callback: (value) => `${value}%`,
      },
      grid: { color: chartGrid },
    },
  },
};

const usageLineOptions = {
  ...baseChartOptions,
  plugins: {
    ...baseChartOptions.plugins,
    legend: { display: false },
  },
};

const cpuLineData = computed(() => ({
  labels: labels.value,
  datasets: [
    {
      label: 'CPU %',
      data: cpuSeries.value,
      borderColor: cpuColor,
      backgroundColor: 'rgba(59, 130, 246, 0.15)',
      fill: true,
      tension: 0.35,
      pointRadius: 2,
    },
  ],
}));

const memoryLineData = computed(() => ({
  labels: labels.value,
  datasets: [
    {
      label: 'Memory %',
      data: memorySeries.value,
      borderColor: memoryColor,
      backgroundColor: 'rgba(16, 185, 129, 0.15)',
      fill: true,
      tension: 0.35,
      pointRadius: 2,
    },
  ],
}));

const cpuGaugeData = computed(() => ({
  labels: ['Used', 'Free'],
  datasets: [
    {
      data: [
        props.agent.metrics?.cpu_usage_percent ?? 0,
        Math.max(0, 100 - (props.agent.metrics?.cpu_usage_percent ?? 0)),
      ],
      backgroundColor: ['rgb(59, 130, 246)', 'rgba(148, 163, 184, 0.2)'],
      borderWidth: 0,
    },
  ],
}));

const memoryGaugeData = computed(() => ({
  labels: ['Used', 'Free'],
  datasets: [
    {
      data: [
        props.agent.metrics?.memory_used_percent ?? 0,
        Math.max(0, 100 - (props.agent.metrics?.memory_used_percent ?? 0)),
      ],
      backgroundColor: ['rgb(16, 185, 129)', 'rgba(148, 163, 184, 0.2)'],
      borderWidth: 0,
    },
  ],
}));

const memorySummary = computed(() => {
  const metrics = props.agent.metrics ?? {};

  return {
    total: metrics.memory_total_bytes ?? 0,
    used: metrics.memory_used_bytes ?? 0,
    free: metrics.memory_free_bytes ?? 0,
    installed: metrics.memory_installed_bytes ?? 0,
    percent: metrics.memory_used_percent ?? 0,
  };
});

const storageSummary = computed(() => {
  const metrics = props.agent.metrics ?? {};

  return {
    volumeTotal: metrics.storage_total_bytes ?? 0,
    volumeUsed: metrics.storage_used_bytes ?? 0,
    volumeFree: metrics.storage_free_bytes ?? 0,
    volumePercent: metrics.storage_used_percent ?? 0,
    physicalTotal: metrics.physical_storage_total_bytes ?? 0,
  };
});

const gaugeOptions = {
  responsive: true,
  maintainAspectRatio: false,
  cutout: '72%',
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (context) => `${context.label}: ${Number(context.raw).toFixed(1)}%`,
      },
    },
  },
};

function appendSnapshot(agent) {
  if (!agent.metrics?.has_metrics) {
    return;
  }

  const stamp = formatChartLabel(agent.last_heartbeat_at || agent.last_seen_at);
  if (labels.value[labels.value.length - 1] === stamp) {
    return;
  }

  labels.value = [...labels.value, stamp].slice(-maxPoints);
  cpuSeries.value = [...cpuSeries.value, agent.metrics.cpu_usage_percent ?? 0].slice(-maxPoints);
  memorySeries.value = [...memorySeries.value, agent.metrics.memory_used_percent ?? 0].slice(-maxPoints);
}

function formatChartLabel(value) {
  if (!value) {
    return new Date().toLocaleTimeString();
  }

  return new Date(value).toLocaleTimeString();
}

function formatBytes(bytes) {
  const value = Number(bytes ?? 0);

  if (!value) {
    return '0 B';
  }

  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const exponent = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
  const scaled = value / 1024 ** exponent;

  return `${scaled.toFixed(scaled >= 10 || exponent === 0 ? 0 : 1)} ${units[exponent]}`;
}

function diskUsageColor(percent) {
  const value = Number(percent ?? 0);

  if (value >= 90) {
    return 'rgb(239, 68, 68)';
  }

  if (value >= 75) {
    return 'rgb(245, 158, 11)';
  }

  return 'rgb(59, 130, 246)';
}

watch(
  () => props.agent,
  (agent) => appendSnapshot(agent),
  { immediate: true, deep: true },
);

onMounted(() => {
  appendSnapshot(props.agent);
});

onBeforeUnmount(() => {
  labels.value = [];
  cpuSeries.value = [];
  memorySeries.value = [];
});
</script>

<template>
  <section v-if="agent.metrics?.has_metrics" class="space-y-6">
    <section class="grid gap-4 md:grid-cols-2">
      <article class="rounded-2xl border border-hairline bg-base-300 p-4">
        <div class="text-caption text-muted">Total RAM</div>
        <div class="mt-2 text-title-md text-body font-mono-num">{{ formatBytes(memorySummary.total) }}</div>
        <p class="mt-2 text-body-sm text-muted">
          {{ formatBytes(memorySummary.used) }} used · {{ formatBytes(memorySummary.free) }} free
          <span v-if="memorySummary.percent">({{ Number(memorySummary.percent).toFixed(1) }}%)</span>
        </p>
        <p v-if="memorySummary.installed > memorySummary.total" class="mt-1 text-caption text-muted">
          Installed modules: {{ formatBytes(memorySummary.installed) }}
        </p>
      </article>

      <article class="rounded-2xl border border-hairline bg-base-300 p-4">
        <div class="text-caption text-muted">Total Storage</div>
        <div class="mt-2 text-title-md text-body font-mono-num">
          {{ formatBytes(storageSummary.volumeTotal || storageSummary.physicalTotal) }}
        </div>
        <p v-if="storageSummary.volumeTotal" class="mt-2 text-body-sm text-muted">
          Volumes: {{ formatBytes(storageSummary.volumeUsed) }} used · {{ formatBytes(storageSummary.volumeFree) }} free
          <span v-if="storageSummary.volumePercent">({{ Number(storageSummary.volumePercent).toFixed(1) }}%)</span>
        </p>
        <p v-if="storageSummary.physicalTotal" class="mt-1 text-body-sm text-muted">
          Physical drives: {{ formatBytes(storageSummary.physicalTotal) }}
        </p>
        <p v-if="!storageSummary.volumeTotal && !storageSummary.physicalTotal" class="mt-2 text-body-sm text-muted">
          No storage capacity reported yet.
        </p>
      </article>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
      <article class="panel-card p-5">
        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="eyebrow">CPU</div>
            <h2 class="mt-3 text-title-md text-body">Processor Load</h2>
            <p v-if="agent.metrics.cpu_brand" class="mt-1 text-body-sm text-muted">{{ agent.metrics.cpu_brand }}</p>
          </div>
          <div class="text-number-display">{{ (agent.metrics.cpu_usage_percent ?? 0).toFixed(1) }}%</div>
        </div>
        <div class="mt-4 grid gap-4 md:grid-cols-[160px_minmax(0,1fr)] items-center">
          <div class="h-40">
            <Doughnut :data="cpuGaugeData" :options="gaugeOptions" />
          </div>
          <div class="h-48">
            <Line :data="cpuLineData" :options="usageLineOptions" />
          </div>
        </div>
      </article>

      <article class="panel-card p-5">
        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="eyebrow">Memory</div>
            <h2 class="mt-3 text-title-md text-body">RAM Usage</h2>
            <p class="mt-1 text-body-sm text-muted font-mono-num">
              {{ formatBytes(memorySummary.used) }} / {{ formatBytes(memorySummary.total) }}
            </p>
          </div>
          <div class="text-number-display">{{ (agent.metrics.memory_used_percent ?? 0).toFixed(1) }}%</div>
        </div>
        <div class="mt-4 grid gap-4 md:grid-cols-[160px_minmax(0,1fr)] items-center">
          <div class="h-40">
            <Doughnut :data="memoryGaugeData" :options="gaugeOptions" />
          </div>
          <div class="h-48">
            <Line :data="memoryLineData" :options="usageLineOptions" />
          </div>
        </div>
      </article>
    </div>

    <article v-if="agent.metrics.disks?.length || storageSummary.physicalTotal" class="panel-card p-5">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div class="eyebrow">Storage</div>
          <h2 class="mt-3 text-title-md text-body">Disk Usage</h2>
          <p class="mt-1 text-body-sm text-muted">
            <span v-if="storageSummary.volumeTotal">
              Total volume capacity {{ formatBytes(storageSummary.volumeTotal) }}
            </span>
            <span v-if="storageSummary.volumeTotal && storageSummary.physicalTotal"> · </span>
            <span v-if="storageSummary.physicalTotal">
              Physical drives {{ formatBytes(storageSummary.physicalTotal) }}
            </span>
          </p>
        </div>
        <div class="status-chip">{{ agent.metrics.disks?.length ?? 0 }} volumes</div>
      </div>

      <div v-if="agent.metrics.disks?.length" class="mt-5 space-y-4">
        <div
          v-for="disk in agent.metrics.disks"
          :key="disk.name"
          class="rounded-2xl border border-hairline bg-base-300 p-4"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div class="font-medium text-body">{{ disk.name }}</div>
              <div class="mt-1 text-caption text-muted">
                {{ disk.filesystem || disk.type || 'Volume' }}
              </div>
            </div>
            <div class="text-right text-body-sm">
              <div class="font-mono-num text-body">{{ formatBytes(disk.used_bytes) }} / {{ formatBytes(disk.total_bytes) }}</div>
              <div class="text-caption text-muted">{{ (disk.used_percent ?? 0).toFixed(1) }}% used</div>
            </div>
          </div>

          <div class="mt-4 h-3 overflow-hidden rounded-full bg-base-100">
            <div
              class="h-full rounded-full transition-all"
              :style="{
                width: `${Math.min(100, Math.max(0, disk.used_percent ?? 0))}%`,
                backgroundColor: diskUsageColor(disk.used_percent),
              }"
            />
          </div>
        </div>
      </div>

      <p v-else class="mt-5 text-body-sm text-muted">
        Volume usage is unavailable. Physical drive inventory is listed below on this page.
      </p>
    </article>
  </section>
</template>
