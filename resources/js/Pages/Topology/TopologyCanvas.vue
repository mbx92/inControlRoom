<script setup>
import { ref, computed, markRaw, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { VueFlow, Position, Panel, SelectionMode } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import SiteGroupNode from './nodes/SiteGroupNode.vue';
import ProxmoxIntegrationNode from './nodes/ProxmoxIntegrationNode.vue';
import ProxmoxGuestNode from './nodes/ProxmoxGuestNode.vue';
import DockerIntegrationNode from './nodes/DockerIntegrationNode.vue';
import DockerContainerNode from './nodes/DockerContainerNode.vue';
import NvrIntegrationNode from './nodes/NvrIntegrationNode.vue';
import CctvCameraNode from './nodes/CctvCameraNode.vue';
import FloorGroupNode from './nodes/FloorGroupNode.vue';
import LocationGroupNode from './nodes/LocationGroupNode.vue';
import RoomGroupNode from './nodes/RoomGroupNode.vue';
import CategoryGroupNode from './nodes/CategoryGroupNode.vue';
import InventoryAssetNode from './nodes/InventoryAssetNode.vue';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps({
    initialNodes: { type: Array, required: true },
    initialEdges: { type: Array, required: true },
    graphMeta: { type: Object, default: () => ({}) },
    mode: { type: String, default: 'infrastructure' },
    siteId: { type: String, default: null },
    topologyLayout: { type: Object, default: null },
});

const fullscreen = defineModel('fullscreen', { type: Boolean, default: false });
const page = usePage();
const canEditLayout = computed(() => page.props.auth.permissions?.is_admin ?? false);

// ─── Constants ────────────────────────────────────────────────────────────────

const MODES = [
    { id: 'infrastructure', label: 'Infrastructure', description: 'Physical assets by site and location' },
    { id: 'proxmox', label: 'Proxmox', description: 'Hypervisor with VM / CT workloads' },
    { id: 'docker', label: 'Docker', description: 'Docker host with container workloads' },
    { id: 'nvr', label: 'NVR', description: 'NVR with CCTV camera streams' },
    { id: 'network', label: 'Network', description: 'Floor / room / uplink links' },
];

// Auto-positioned relative to parent — not user-draggable
const PINNED_NODE_TYPES = new Set(['category-group', 'room-group']);

// User-draggable; floor-group still pulls its rooms/assets when dragged.
// site-group is independently movable — it no longer anchors its children.
const GROUP_DRAG_TYPES = new Set(['floor-group']);

const OFFLINE_EDGE_COLOR = '#F6465D';
const LAYOUT_ORIGIN_Y = 24;
const VIRTUAL_PINNED_NODE_TYPES = new Set(['proxmox-guest', 'docker-container', 'cctv-camera']);
const MEMBERSHIP_EDGE_COLOR = '#707A8A';
const VIRTUAL_INTEGRATION_TYPES = new Set(['proxmox-integration', 'docker-integration', 'nvr-integration']);
const VIRTUAL_WORKLOAD_TYPES = new Set(['proxmox-guest', 'docker-container', 'cctv-camera']);
const FULL_VIRTUAL_MODES = new Set(['proxmox', 'docker', 'nvr']);

const NODE_WIDTH = {
    'site-group': 280,
    'location-group': 260,
    'category-group': 170,
    'floor-group': 240,
    'room-group': 150,
    'inventory-asset': 260,
    'proxmox-integration': 228,
    'proxmox-guest': 186,
    'docker-integration': 228,
    'docker-container': 206,
    'nvr-integration': 228,
    'cctv-camera': 206,
};

const NODE_HEIGHT = {
    'site-group': 52,
    'location-group': 52,
    'category-group': 48,
    'floor-group': 48,
    'room-group': 44,
    'inventory-asset': 40,
    'proxmox-integration': 58,
    'proxmox-guest': 56,
    'docker-integration': 58,
    'docker-container': 56,
    'nvr-integration': 58,
    'cctv-camera': 56,
};

const NETWORK_ROLE_PRIORITY = {
    'edge-router': 0,
    'router': 1,
    'core-switch': 2,
    'distribution-switch': 3,
    'access-switch': 4,
    'switch': 5,
    'hypervisor': 6,
    'server': 7,
    'nas': 8,
    'nvr': 9,
    'cctv': 10,
    'access-door': 11,
    'access-point': 12,
    'printer': 13,
    'pc': 14,
    'laptop': 15,
    'device': 20,
};

const nodeTypes = {
    'site-group': markRaw(SiteGroupNode),
    'location-group': markRaw(LocationGroupNode),
    'proxmox-integration': markRaw(ProxmoxIntegrationNode),
    'proxmox-guest': markRaw(ProxmoxGuestNode),
    'docker-integration': markRaw(DockerIntegrationNode),
    'docker-container': markRaw(DockerContainerNode),
    'nvr-integration': markRaw(NvrIntegrationNode),
    'cctv-camera': markRaw(CctvCameraNode),
    'category-group': markRaw(CategoryGroupNode),
    'floor-group': markRaw(FloorGroupNode),
    'room-group': markRaw(RoomGroupNode),
    'inventory-asset': markRaw(InventoryAssetNode),
};

// ─── State ────────────────────────────────────────────────────────────────────

const nodes = ref([]);
const edges = ref([]);
const graphReady = ref(false);

/**
 * pendingLayoutNodes retains `parentId` (stripped before passing to VueFlow)
 * and is the source-of-truth for edge routing calculations.
 */
const pendingLayoutNodes = ref([]);

const isLocked = ref(false);
const isSavingLayout = ref(false);
const selectionMode = ref(false);
const legendOpen = ref(true);

/**
 * After a save, layoutOverride holds the latest persisted state so we don't
 * need to wait for an Inertia prop refresh. `undefined` means "use prop".
 */
const layoutOverride = ref(undefined);

/** Snapshot of all node positions taken at drag-start for group-drag delta. */
const preDragSnapshot = ref({});

/** fitView ref provided by VueFlow's onNodesInitialized callback. */
const fitViewRef = ref(null);

/**
 * Category/room nodes removed from VueFlow graph — listed here for the legend.
 * Derived from pendingLayoutNodes which retains the full hierarchy.
 */
const hiddenGroupLabels = computed(() =>
    pendingLayoutNodes.value
        .filter((n) => PINNED_NODE_TYPES.has(n.type))
        .map((n) => ({
            label: n.data?.label ?? '',
            floor: n.data?.floor ?? '',
            icon: n.data?.icon ?? '',
            type: n.type,
        })),
);

// ─── CSRF helper ─────────────────────────────────────────────────────────────

function readCsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

// ─── Layout persistence (DB) ──────────────────────────────────────────────────

function resolvedLayout() {
    return layoutOverride.value !== undefined ? layoutOverride.value : props.topologyLayout;
}

function loadLockState() {
    isLocked.value = resolvedLayout()?.is_locked ?? false;
}

function savedPositions() {
    return resolvedLayout()?.positions ?? {};
}

function shouldPersistNode(node) {
    if (PINNED_NODE_TYPES.has(node.type)) return false;
    if (!FULL_VIRTUAL_MODES.has(props.mode) && VIRTUAL_PINNED_NODE_TYPES.has(node.type)) return false;
    if (!FULL_VIRTUAL_MODES.has(props.mode) && isHostLinkedProxmox(node)) return false;
    return true;
}

function collectPersistablePositionsFrom(nodes_) {
    const out = {};
    for (const n of nodes_) {
        if (!shouldPersistNode(n)) continue;
        out[n.id] = { x: n.position.x, y: n.position.y };
    }
    return out;
}

function collectPositions() {
    return collectPersistablePositionsFrom(nodes.value);
}

async function persistLayout(positions = null) {
    if (!props.siteId || !canEditLayout.value) return false;

    const payload = {
        site_id: props.siteId,
        mode: props.mode,
        positions: positions ?? collectPositions(),
        is_locked: isLocked.value,
    };

    try {
        isSavingLayout.value = true;
        const res = await fetch(route('topology.layout.update'), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        if (res.ok) {
            layoutOverride.value = {
                positions: payload.positions,
                is_locked: payload.is_locked,
            };
            return true;
        }

        console.error('[Topology] Layout save returned status', res.status, res.statusText);
    } catch (err) {
        console.error('[Topology] Layout save failed:', err);
    } finally {
        isSavingLayout.value = false;
    }

    return false;
}

// ─── Node dimension helpers ───────────────────────────────────────────────────

function nW(node) {
    if (node.type === 'inventory-asset' && node.data?.hostsVirtual) return 280;
    return NODE_WIDTH[node.type] ?? 160;
}
function nH(node) { return NODE_HEIGHT[node.type] ?? 44; }
function nodeCenterX(node) { return node.position.x + nW(node) / 2; }
function isHostLinkedProxmox(node) { return node.type === 'proxmox-integration' && Boolean(node.data?.hostNodeId); }
function isVirtualIntegration(node) { return VIRTUAL_INTEGRATION_TYPES.has(node?.type); }
function isVirtualWorkload(node) { return VIRTUAL_WORKLOAD_TYPES.has(node?.type); }

/**
 * In 'proxmox' mode, guest nodes are freely draggable so their handles must be
 * visible — do NOT mark them as pinned.
 */
function isPinnedLayoutNode(node, mode) {
    if (PINNED_NODE_TYPES.has(node.type)) return true;
    if (FULL_VIRTUAL_MODES.has(mode)) return false;
    return VIRTUAL_PINNED_NODE_TYPES.has(node.type) || isHostLinkedProxmox(node);
}

function getSortedVirtualGuests(nodes_) {
    return nodes_
        .filter((node) => isVirtualWorkload(node))
        .sort((a, b) => {
            const aVmid = Number(a.data?.vmid ?? Number.MAX_SAFE_INTEGER);
            const bVmid = Number(b.data?.vmid ?? Number.MAX_SAFE_INTEGER);
            if (aVmid !== bVmid) return aVmid - bVmid;
            return (a.data?.label ?? '').localeCompare(b.data?.label ?? '');
        });
}

function getVirtualWorkloadsForIntegration(nodes_, integrationId) {
    return getSortedVirtualGuests(nodes_)
        .filter((node) => node.data?.integrationNodeId === integrationId);
}

function layoutRank(node) {
    if (node.type === 'site-group') return -500;
    if (node.type === 'floor-group') return -400;
    if (node.type === 'location-group') return -350;
    if (node.type === 'room-group') return -300;
    if (node.type === 'category-group') return -250;
    if (isVirtualIntegration(node)) return -200;
    if (isVirtualWorkload(node)) return -100;

    if (node.type === 'inventory-asset') {
        const role = String(node.data?.networkRole ?? '').toLowerCase();
        const category = String(node.data?.category ?? '').toLowerCase();

        if (props.mode === 'network') {
            return NETWORK_ROLE_PRIORITY[role] ?? NETWORK_ROLE_PRIORITY[category] ?? 100;
        }

        return role === 'hypervisor' ? 0 : 100;
    }

    return 999;
}

function compareLayoutNodes(a, b) {
    const rankDiff = layoutRank(a) - layoutRank(b);
    if (rankDiff !== 0) return rankDiff;
    return (a.data?.label ?? '').localeCompare(b.data?.label ?? '');
}

function buildVirtualBranchPositions({ proxmox, guests, metrics, host = null }) {
    if (!proxmox) return {};

    let proxmoxX;
    let proxmoxY;

    if (host) {
        proxmoxX = host.position.x + nW(host) + metrics.virtualHostGap;
        proxmoxY = host.position.y + nH(host) / 2 - nH(proxmox) / 2;
    } else {
        proxmoxX = proxmox.position.x;
        proxmoxY = proxmox.position.y;
    }

    const positions = {
        [proxmox.id]: { x: proxmoxX, y: proxmoxY },
    };

    const guestWidth = guests[0] ? nW(guests[0]) : NODE_WIDTH['proxmox-guest'];
    const guestX = proxmoxX + Math.max(0, (nW(proxmox) - guestWidth) / 2);
    let guestY = proxmoxY + nH(proxmox) + metrics.virtualTierGap;

    for (const guest of guests) {
        positions[guest.id] = { x: guestX, y: guestY };
        guestY += nH(guest) + metrics.virtualGuestGap;
    }

    return positions;
}

function virtualBranchExtraWidth(proxmoxNode, guestCount, metrics) {
    if (!proxmoxNode) return 0;
    const branchW = metrics.virtualHostGap + nW(proxmoxNode);
    const guestStackW = proxmoxNode.type === 'docker-integration'
        ? NODE_WIDTH['docker-container']
        : NODE_WIDTH['proxmox-guest'];
    return Math.max(branchW, guestStackW);
}

/** Horizontal gap between guest cards in a row */
const PROXMOX_GUEST_ROW_GAP = 16;

/**
 * Total pixel width consumed by N guest cards placed in a row.
 */
function guestRowWidth(count, nodeWidth = NODE_WIDTH['proxmox-guest']) {
    if (count <= 0) return 0;
    return count * nodeWidth + (count - 1) * PROXMOX_GUEST_ROW_GAP;
}

/**
 * Place guest nodes in a horizontal row, centred on `centerX`.
 * Returns the Y coordinate of the row's bottom edge.
 */
function placeGuestRow(guests, centerX, y) {
    if (!guests.length) return y;
    const gW = nW(guests[0]);
    let x = centerX - guestRowWidth(guests.length, gW) / 2;
    for (const guest of guests) {
        guest.position = { x, y };
        guest.sourcePosition = Position.Bottom;
        guest.targetPosition = Position.Top;
        x += gW + PROXMOX_GUEST_ROW_GAP;
    }
    return y + nH(guests[0]);
}

/**
 * Proxmox topology layout:
 *   Site (centred)
 *     └─ Proxmox integration (centred)
 *           ├─ running VM/CT  ···  running VM/CT   (horizontal row)
 *           └─ stopped/offline VM/CT ··· (second row below, if any)
 */
function layoutProxmoxHierarchy(rawNodes) {
    const cloned = rawNodes.map((n) => ({ ...n, position: { x: 0, y: 0 } }));
    const metrics = getLayoutMetrics(cloned.length);
    const site = cloned.find((n) => n.type === 'site-group');
    const integrations = cloned.filter((n) => isVirtualIntegration(n));
    const startX = 40;

    const branches = integrations.map((integrationNode) => {
        const workloads = getVirtualWorkloadsForIntegration(cloned, integrationNode.id);
        const onlineGuests = workloads.filter((g) => g.data?.status === 'running');
        const offlineGuests = workloads.filter((g) => g.data?.status !== 'running');
        const guestWidth = workloads[0] ? nW(workloads[0]) : NODE_WIDTH['proxmox-guest'];
        const branchWidth = Math.max(
            nW(integrationNode),
            guestRowWidth(onlineGuests.length, guestWidth),
            guestRowWidth(offlineGuests.length, guestWidth),
        );

        return {
            integrationNode,
            workloads,
            onlineGuests,
            offlineGuests,
            guestWidth,
            branchWidth,
        };
    });

    const contentW = Math.max(
        site ? nW(site) : 200,
        branches.reduce((sum, branch) => sum + branch.branchWidth, 0)
            + Math.max(0, branches.length - 1) * metrics.colGap,
    );
    const cx = startX + contentW / 2;

    let y = LAYOUT_ORIGIN_Y;

    if (site) {
        site.position = { x: cx - nW(site) / 2, y };
        site.sourcePosition = Position.Bottom;
        site.targetPosition = Position.Top;
        y += nH(site) + metrics.tierGap;
    }

    let branchX = startX;
    for (const branch of branches) {
        const branchCenterX = branchX + branch.branchWidth / 2;
        let branchY = y;

        branch.integrationNode.position = { x: branchCenterX - nW(branch.integrationNode) / 2, y: branchY };
        branch.integrationNode.sourcePosition = Position.Bottom;
        branch.integrationNode.targetPosition = Position.Top;
        branchY += nH(branch.integrationNode) + metrics.tierGap;

        if (branch.onlineGuests.length) {
            const rowBottom = placeGuestRow(branch.onlineGuests, branchCenterX, branchY);
            branchY = rowBottom + metrics.tierGap;
        }

        if (branch.offlineGuests.length) {
            placeGuestRow(branch.offlineGuests, branchCenterX, branchY);
        }

        branchX += branch.branchWidth + metrics.colGap;
    }

    return cloned;
}

/**
 * Re-anchor the Proxmox branch below the current site position,
 * preserving the same online-row / offline-row separation.
 * Called after the site node is dragged.
 */
function relayoutProxmoxVertical(nodes_) {
    const metrics = getLayoutMetrics(nodes_.length);
    const site = nodes_.find((n) => n.type === 'site-group');
    const integrations = nodes_.filter((n) => isVirtualIntegration(n));

    if (!integrations.length || !site) return nodes_;

    const branches = integrations.map((integrationNode) => {
        const workloads = getVirtualWorkloadsForIntegration(nodes_, integrationNode.id);
        const onlineGuests = workloads.filter((g) => g.data?.status === 'running');
        const offlineGuests = workloads.filter((g) => g.data?.status !== 'running');
        const guestWidth = workloads[0] ? nW(workloads[0]) : NODE_WIDTH['proxmox-guest'];
        const branchWidth = Math.max(
            nW(integrationNode),
            guestRowWidth(onlineGuests.length, guestWidth),
            guestRowWidth(offlineGuests.length, guestWidth),
        );

        return {
            integrationNode,
            onlineGuests,
            offlineGuests,
            guestWidth,
            branchWidth,
        };
    });

    const contentW = Math.max(
        nW(site),
        branches.reduce((sum, branch) => sum + branch.branchWidth, 0)
            + Math.max(0, branches.length - 1) * metrics.colGap,
    );
    const cx = site.position.x + nW(site) / 2;
    const startX = cx - contentW / 2;

    const positions = {};
    const guestPositions = {};
    let branchX = startX;

    for (const branch of branches) {
        const branchCenterX = branchX + branch.branchWidth / 2;
        let branchY = site.position.y + nH(site) + metrics.tierGap;
        positions[branch.integrationNode.id] = { x: branchCenterX - nW(branch.integrationNode) / 2, y: branchY };
        branchY += nH(branch.integrationNode) + metrics.tierGap;

        const gW = branch.onlineGuests[0]
            ? nW(branch.onlineGuests[0])
            : (branch.offlineGuests[0] ? nW(branch.offlineGuests[0]) : branch.guestWidth);

        if (branch.onlineGuests.length) {
            let x = branchCenterX - guestRowWidth(branch.onlineGuests.length, branch.guestWidth) / 2;
            for (const guest of branch.onlineGuests) {
                guestPositions[guest.id] = { x, y: branchY };
                x += gW + PROXMOX_GUEST_ROW_GAP;
            }
            branchY += nH(branch.onlineGuests[0]) + metrics.tierGap;
        }

        if (branch.offlineGuests.length) {
            let x = branchCenterX - guestRowWidth(branch.offlineGuests.length, branch.guestWidth) / 2;
            for (const guest of branch.offlineGuests) {
                guestPositions[guest.id] = { x, y: branchY };
                x += gW + PROXMOX_GUEST_ROW_GAP;
            }
        }

        branchX += branch.branchWidth + metrics.colGap;
    }

    return nodes_.map((node) => {
        if (positions[node.id])     return { ...node, position: { ...positions[node.id] } };
        if (guestPositions[node.id]) return { ...node, position: { ...guestPositions[node.id] } };
        return node;
    });
}

// ─── Auto-layout hierarchy ────────────────────────────────────────────────────

function getLayoutMetrics(count) {
    const layout = count >= 100
        ? { tierGap: 52, assetGap: 36, colGap: 12, virtualHostGap: 56, virtualTierGap: 64, virtualGuestGap: 28, virtualDetachedGap: 72 }
        : count >= 50
            ? { tierGap: 68, assetGap: 46, colGap: 18, virtualHostGap: 64, virtualTierGap: 80, virtualGuestGap: 34, virtualDetachedGap: 88 }
            : { tierGap: 84, assetGap: 58, colGap: 24, virtualHostGap: 72, virtualTierGap: 96, virtualGuestGap: 48, virtualDetachedGap: 100 };

    if (FULL_VIRTUAL_MODES.has(props.mode)) {
        return layout;
    }

    return {
        ...layout,
        childTierGap: layout.tierGap + 18,
        childAssetGap: layout.assetGap + 18,
        colGap: layout.colGap + 6,
    };
}

function layoutHierarchy(rawNodes) {
    if (FULL_VIRTUAL_MODES.has(props.mode)) {
        return layoutProxmoxHierarchy(rawNodes);
    }

    const cloned = rawNodes.map((n) => ({ ...n, position: { x: 0, y: 0 } }));
    const metrics = getLayoutMetrics(cloned.length);
    const site    = cloned.find((n) => n.type === 'site-group');
    const proxmox = cloned.find((n) => isVirtualIntegration(n));
    const guests  = getSortedVirtualGuests(cloned);

    // Build parent→children map
    const childrenOf = {};
    for (const n of cloned) {
        if (!n.parentId) continue;
        (childrenOf[n.parentId] ??= []).push(n);
    }
    for (const ch of Object.values(childrenOf)) {
        ch.sort(compareLayoutNodes);
    }

    /**
     * A node is a "leaf" if it has NO children in the childrenOf map.
     * Container nodes (even inventory-asset uplink switches) must be recursed.
     */
    function isLeaf(nodeId) {
        return !(childrenOf[nodeId]?.length > 0);
    }

    function colWidth(nodeId) {
        const n = cloned.find((x) => x.id === nodeId);
        if (!n) return 160;
        let w = nW(n);
        if (n.type === 'inventory-asset' && n.data?.hostsVirtual && proxmox) {
            w += virtualBranchExtraWidth(proxmox, guests.length, metrics);
        }
        for (const ch of childrenOf[nodeId] ?? []) {
            const childW = isLeaf(ch.id)
                ? nW(ch) + ((ch.data?.hostsVirtual && proxmox)
                    ? virtualBranchExtraWidth(proxmox, guests.length, metrics)
                    : 0)
                : colWidth(ch.id);
            w = Math.max(w, childW);
        }
        return w;
    }

    function placeColumn(nodeId, ox, oy) {
        const n = cloned.find((x) => x.id === nodeId);
        if (!n) return oy;

        const cw = colWidth(nodeId);
        n.position = { x: ox + cw / 2 - nW(n) / 2, y: oy };
        n.sourcePosition = Position.Bottom;
        n.targetPosition = Position.Top;

        let y = oy + nH(n) + (metrics.childTierGap ?? metrics.tierGap);
        for (const ch of childrenOf[nodeId] ?? []) {
            if (isLeaf(ch.id)) {
                // True leaf: place inline (stacked vertically)
                ch.position = { x: ox + cw / 2 - nW(ch) / 2, y };
                ch.sourcePosition = Position.Bottom;
                ch.targetPosition = Position.Top;
                y += nH(ch) + (metrics.childAssetGap ?? metrics.assetGap);
            } else {
                // Has children (uplink container, room, category…): recurse
                y = placeColumn(ch.id, ox, y);
            }
        }
        return y;
    }

    function placeVirtual() {
        if (!proxmox) return;

        const hostNodeId = proxmox.data?.hostNodeId ?? null;
        const host = hostNodeId
            ? cloned.find((n) => n.id === hostNodeId && n.position.y > 0)
            : null;
        const positions = buildVirtualBranchPositions({ proxmox, guests, metrics, host });

        proxmox.position = positions[proxmox.id] ?? proxmox.position;
        proxmox.sourcePosition = Position.Bottom;
        proxmox.targetPosition = Position.Top;

        for (const guest of guests) {
            guest.position = positions[guest.id] ?? guest.position;
            guest.sourcePosition = Position.Bottom;
            guest.targetPosition = Position.Top;
        }
    }

    const floorNodes = site ? (childrenOf[site.id] ?? []) : [];
    const floorCols  = floorNodes.map((f) => ({ f, cw: colWidth(f.id) }));

    const hostLinked = Boolean(proxmox?.data?.hostNodeId);
    // Check if the host asset actually exists in this graph (avoids orphaned proxmox)
    const hostExists = hostLinked
        && Boolean(cloned.find((n) => n.id === proxmox.data.hostNodeId));

    // Add extra width for proxmox column when it must stand alone from the site branch.
    const proxExtraW = proxmox && !hostExists
        ? Math.max(
            nW(proxmox),
            guests[0] ? nW(guests[0]) : NODE_WIDTH['proxmox-guest'],
        ) + metrics.virtualDetachedGap
        : 0;

    const totalW = floorCols.reduce((s, c) => s + c.cw, 0)
        + Math.max(0, floorCols.length - 1) * metrics.colGap
        + proxExtraW;

    const startX = 40;
    const floorY = LAYOUT_ORIGIN_Y + metrics.tierGap + 16;

    if (site) {
        site.position = {
            x: startX + Math.max(totalW, nW(site)) / 2 - nW(site) / 2,
            y: LAYOUT_ORIGIN_Y,
        };
        site.sourcePosition = Position.Bottom;
        site.targetPosition = Position.Top;
        site.zIndex = 0;
    }

    let colX = startX;
    for (const { f, cw } of floorCols) {
        placeColumn(f.id, colX, floorY);
        colX += cw + metrics.colGap;
    }

    if (proxmox) {
        // Always set a default anchor first — placeVirtual will override when needed.
        proxmox.position = { x: colX, y: floorY };
        proxmox.sourcePosition = Position.Bottom;
        proxmox.targetPosition = Position.Top;
        placeVirtual();
    }

    // ── Fallback: any node still at (0, 0) was not placed by the algorithm.
    // Position it in a row below the main layout so it never causes ghost lines.
    const unpositioned = cloned.filter((n) => n.position.x === 0 && n.position.y === 0);
    if (unpositioned.length > 0) {
        const maxY = cloned.reduce((m, n) => Math.max(m, n.position.y + nH(n)), 0);
        const fallbackY = maxY + metrics.tierGap + 24;
        let fallbackX = startX;
        for (const node of unpositioned) {
            node.position = { x: fallbackX, y: fallbackY };
            node.sourcePosition = Position.Bottom;
            node.targetPosition = Position.Top;
            fallbackX += nW(node) + metrics.colGap;
        }
    }

    return cloned;
}

// ─── Position merging ─────────────────────────────────────────────────────────

/**
 * Apply saved positions from DB to auto-layout nodes.
 * - GROUP_DRAG_TYPES (site/floor): accept saved if not an outlier
 * - Other draggables (assets/guests): accept saved; only reject corrupt or outlier values
 * - PINNED_NODE_TYPES (category/room): always skipped — handled by recalcPinned()
 * - host-linked proxmox: always skipped — handled by recalcVirtualBranch()
 *
 * Outlier check: if a saved position is more than OUTLIER_FACTOR × the auto-layout
 * extent away from the expected position, discard it. This catches nodes that were
 * accidentally dragged off-screen or saved with bad data from legacy sessions.
 */
function mergePositions(autoNodes) {
    const saved = savedPositions();
    if (!saved || !Object.keys(saved).length) return autoNodes;

    // Compute auto-layout bounding box to define the "reasonable" region
    const xs = autoNodes.map((n) => n.position.x);
    const ys = autoNodes.map((n) => n.position.y);
    const autoXMin = Math.min(...xs);
    const autoXMax = Math.max(...xs);
    const autoYMin = Math.min(...ys);
    const autoYMax = Math.max(...ys);
    const extentW = Math.max(autoXMax - autoXMin, 400);
    const extentH = Math.max(autoYMax - autoYMin, 400);
    const OUTLIER = 3; // allow up to 3× the extent outside auto-layout bounds
    const xLo = autoXMin - extentW * OUTLIER;
    const xHi = autoXMax + extentW * OUTLIER;
    const yLo = autoYMin - extentH * OUTLIER;
    const yHi = autoYMax + extentH * OUTLIER;

    return autoNodes.map((node) => {
        if (PINNED_NODE_TYPES.has(node.type)) return node;
        // In proxmox mode, guest positions may be user-saved — allow restoring them
        if (!FULL_VIRTUAL_MODES.has(props.mode) && VIRTUAL_PINNED_NODE_TYPES.has(node.type)) return node;
        if (!FULL_VIRTUAL_MODES.has(props.mode) && isHostLinkedProxmox(node)) return node;

        const s = saved[node.id];
        if (!s) return node;

        // Reject clearly corrupt values
        if (!Number.isFinite(s.x) || !Number.isFinite(s.y)) return node;

        // Reject outlier positions that are far outside the auto-layout region
        if (s.x < xLo || s.x > xHi || s.y < yLo || s.y > yHi) return node;

        return { ...node, position: { x: s.x, y: s.y } };
    });
}

/**
 * Re-position PINNED nodes (category/room) relative to their parent's CURRENT
 * position (after mergePositions has potentially moved the parent floor/site).
 * This keeps the visual hierarchy intact even when floors have been dragged.
 */
function recalcPinned(nodes_, autoById) {
    const byId = Object.fromEntries(nodes_.map((n) => [n.id, n]));

    return nodes_.map((node) => {
        if (!PINNED_NODE_TYPES.has(node.type)) return node;

        const autoNode = autoById[node.id];
        if (!autoNode) return node;

        const parentId = node.parentId;
        if (!parentId) return { ...node, position: { ...autoNode.position } };

        const autoParent = autoById[parentId];
        const curParent  = byId[parentId];
        if (!autoParent || !curParent) return { ...node, position: { ...autoNode.position } };

        const dx = curParent.position.x - autoParent.position.x;
        const dy = curParent.position.y - autoParent.position.y;

        return {
            ...node,
            position: {
                x: autoNode.position.x + dx,
                y: autoNode.position.y + dy,
            },
        };
    });
}

// ─── Interaction flags ────────────────────────────────────────────────────────

function applyFlags(nodes_, mode) {
    return nodes_.map((node) => {
        const pinned = isPinnedLayoutNode(node, mode);
        return {
            ...node,
            selectable: !pinned,
            draggable: !pinned,
            class: pinned ? 'topology-node--pinned' : undefined,
        };
    });
}

/** Strip parentId before handing nodes to VueFlow (VueFlow must not parent them).
 *  Category/room nodes are excluded from the VueFlow graph — they're used only
 *  for layout calculations and are shown as a legend instead. */
function toFlowNodes(nodes_) {
    return nodes_
        .filter((n) => !PINNED_NODE_TYPES.has(n.type))
        .map(({ parentId: _p, ...n }) => ({ ...n, draggable: !isLocked.value }));
}

/**
 * Edge-aware tidy pass for infra/network:
 * rebuild a spacious tree so every subtree gets its own horizontal lane.
 */
function tidyBranchLayout(nodes_) {
    if (props.mode === 'proxmox') return nodes_;

    const cloned = nodes_.map((node) => ({
        ...node,
        position: { ...node.position },
    }));
    const childrenOf = {};
    const metrics = getLayoutMetrics(cloned.length);
    const horizontalGap = props.mode === 'network' ? 180 : 150;
    const rootGap = horizontalGap + 80;
    const verticalGap = props.mode === 'network' ? 150 : 135;
    const spanMemo = new Map();

    for (const node of cloned) {
        if (!node.parentId) continue;
        (childrenOf[node.parentId] ??= []).push(node);
    }

    for (const children of Object.values(childrenOf)) {
        children.sort(compareLayoutNodes);
    }

    function nodeSpan(node) {
        if (spanMemo.has(node.id)) return spanMemo.get(node.id);

        const children = childrenOf[node.id] ?? [];
        const childWidth = children.reduce((sum, child) => sum + nodeSpan(child), 0)
            + Math.max(0, children.length - 1) * horizontalGap;
        const ownWidth = PINNED_NODE_TYPES.has(node.type) ? 0 : nW(node);
        const span = Math.max(ownWidth, childWidth, 1);

        spanMemo.set(node.id, span);
        return span;
    }

    function placeSubtree(node, left, y) {
        const span = nodeSpan(node);
        const pinned = PINNED_NODE_TYPES.has(node.type);

        node.position = {
            x: left + span / 2 - nW(node) / 2,
            y,
        };
        node.sourcePosition = Position.Bottom;
        node.targetPosition = Position.Top;

        const children = childrenOf[node.id] ?? [];
        if (!children.length) return;

        const childWidth = children.reduce((sum, child) => sum + nodeSpan(child), 0)
            + Math.max(0, children.length - 1) * horizontalGap;
        let cursorX = left + (span - childWidth) / 2;
        const childY = pinned ? y : y + nH(node) + verticalGap;

        for (const child of children) {
            placeSubtree(child, cursorX, childY);
            cursorX += nodeSpan(child) + horizontalGap;
        }
    }

    const roots = cloned
        .filter((node) => !node.parentId)
        .sort(compareLayoutNodes);
    let cursorX = 40;

    for (const root of roots) {
        placeSubtree(root, cursorX, LAYOUT_ORIGIN_Y);
        cursorX += nodeSpan(root) + rootGap;
    }

    if (cloned.some((node) => isVirtualIntegration(node))) {
        return recalcVirtualBranch(cloned, Object.fromEntries(cloned.map((node) => [node.id, node])));
    }

    return cloned;
}

function buildGraphNodes({ useSavedPositions = true, tidy = false } = {}) {
    const auto     = layoutHierarchy(props.initialNodes);
    const autoById = Object.fromEntries(auto.map((n) => [n.id, n]));

    const withSaved   = useSavedPositions ? mergePositions(auto) : auto;
    const withPinned  = recalcPinned(withSaved, autoById);
    const hasVirtual  = withPinned.some((n) => isVirtualIntegration(n));
    const withVirtual = FULL_VIRTUAL_MODES.has(props.mode)
        ? withPinned
        : hasVirtual
            ? recalcVirtualBranch(withPinned, autoById)
            : withPinned;
    const tidied      = tidy ? tidyBranchLayout(withVirtual) : withVirtual;

    return applyFlags(tidied, props.mode);
}

// ─── Apply graph ──────────────────────────────────────────────────────────────

/**
 * Keep the virtual branch anchored as a true hierarchy:
 * host asset → Proxmox integration → VM / CT guests.
 * Host-linked Proxmox and all guests are always recomputed, never restored from
 * saved node positions, so legacy drags cannot break the hierarchy.
 */
function recalcVirtualBranch(nodes_, autoById) {
    const metrics = getLayoutMetrics(nodes_.length);
    const byId = Object.fromEntries(nodes_.map((n) => [n.id, n]));

    const proxmox = nodes_.find((n) => isVirtualIntegration(n));
    if (!proxmox) return nodes_;

    const guests = getSortedVirtualGuests(nodes_);
    const hostId = proxmox.data?.hostNodeId ?? null;
    const curHost = hostId ? byId[hostId] : null;
    if (hostId && !curHost) return nodes_;

    const positions = buildVirtualBranchPositions({
        proxmox,
        guests,
        metrics,
        host: curHost,
    });

    return nodes_.map((node) => (
        positions[node.id]
            ? { ...node, position: { ...positions[node.id] } }
            : node
    ));
}

function applyGraph() {
    graphReady.value = false;
    edges.value = [];
    loadLockState();
    pendingLayoutNodes.value = buildGraphNodes();
    nodes.value = toFlowNodes(pendingLayoutNodes.value);
}

// ─── Edge building ────────────────────────────────────────────────────────────

function isNodeOffline(node) {
    if (!node?.data?.status) return false;
    if (node.type === 'proxmox-guest' || node.type === 'docker-container') return node.data.status !== 'running';
    if (node.type === 'inventory-asset') return node.data.status !== 'active';
    return false;
}

function normalizedEdgeVariant(edge, byId) {
    const src = byId[edge.source];
    const tgt = byId[edge.target];
    const variant = edge.data?.variant ?? 'physical';

    if (props.mode === 'network' && variant === 'network') {
        if (src && ['site-group', 'floor-group', 'location-group', 'room-group'].includes(src.type)) {
            return 'membership';
        }
        if (tgt && ['site-group', 'floor-group', 'location-group', 'room-group'].includes(tgt.type)) {
            return 'membership';
        }
    }

    return variant;
}

function edgeTouchesOffline(edge, byId, variant = null) {
    const resolvedVariant = variant ?? normalizedEdgeVariant(edge, byId);
    if (resolvedVariant === 'membership') return false;
    return isNodeOffline(byId[edge.source]) || isNodeOffline(byId[edge.target]);
}

function resolveRouting(edge, byId, routeOffset = 0) {
    const src = byId[edge.source];
    const tgt = byId[edge.target];
    if (!src || !tgt) {
        return { type: 'smoothstep', sourceHandle: 'bottom', targetHandle: 'top', pathOptions: { borderRadius: 8 } };
    }

    const variant = normalizedEdgeVariant(edge, byId);
    const sx  = nodeCenterX(src);
    const tx  = nodeCenterX(tgt);
    const dx  = tx - sx;
    const midY = ((src.position.y + nH(src)) + tgt.position.y) / 2 + routeOffset;
    const metrics = getLayoutMetrics(Object.keys(byId).length);

    if (variant === 'membership') {
        if (Math.abs(dx) <= 24) {
            return {
                type: 'smoothstep',
                sourceHandle: 'left',
                targetHandle: 'left',
                pathOptions: {
                    borderRadius: 12,
                    centerX: Math.min(src.position.x, tgt.position.x) - 32,
                },
            };
        }

        const sourceHandle = dx >= 0 ? 'right' : 'left';
        const targetHandle = dx >= 0 ? 'left' : 'right';

        return {
            type: 'smoothstep',
            sourceHandle,
            targetHandle,
            pathOptions: {
                borderRadius: 12,
                centerX: dx >= 0
                    ? Math.max(src.position.x + nW(src), tgt.position.x) + 18
                    : Math.min(src.position.x, tgt.position.x + nW(tgt)) - 18,
            },
        };
    }

    if (variant === 'hosts') {
        const dy = tgt.position.y - src.position.y;
        const hostMidY = src.position.y + nH(src) / 2;

        // Proxmox sits beside the host asset → clean horizontal link
        if (dx > metrics.virtualHostGap * 0.35 && Math.abs(dy) <= Math.max(nH(src), nH(tgt)) * 1.5) {
            return {
                type: 'smoothstep',
                sourceHandle: 'right',
                targetHandle: 'left',
                pathOptions: { borderRadius: 12, centerY: hostMidY },
            };
        }

        if (dy > 0) {
            if (Math.abs(dx) < 48) {
                return {
                    type: 'straight',
                    sourceHandle: 'bottom',
                    targetHandle: 'top',
                    pathOptions: {},
                };
            }
            return {
                type: 'smoothstep',
                sourceHandle: 'bottom',
                targetHandle: 'top',
                pathOptions: { borderRadius: 12, centerY: midY },
            };
        }

        if (Math.abs(dy) <= nH(src) * 2) {
            const side = dx >= 0 ? 'right' : 'left';
            return {
                type: 'smoothstep',
                sourceHandle: side,
                targetHandle: side === 'right' ? 'left' : 'right',
                pathOptions: { borderRadius: 12, centerY: hostMidY },
            };
        }

        return {
            type: 'smoothstep',
            sourceHandle: dy > 0 ? 'bottom' : 'top',
            targetHandle: dy > 0 ? 'top' : 'bottom',
            pathOptions: { borderRadius: 12, centerY: midY },
        };
    }

    // In proxmox mode: fan-out from proxmox bottom to each guest's top.
    // Use a generous borderRadius so the curves spread cleanly from a single
    // bottom handle across the horizontal row.
    if (FULL_VIRTUAL_MODES.has(props.mode)) {
        return {
            type: 'smoothstep',
            sourceHandle: 'bottom',
            targetHandle: 'top',
            pathOptions: { borderRadius: 20, centerY: midY },
        };
    }

    if (variant === 'physical' || variant === 'network' || variant === 'virtual') {
        if (isVirtualIntegration(src) && isVirtualWorkload(tgt)) {
            return {
                type: 'smoothstep',
                sourceHandle: 'bottom',
                targetHandle: 'top',
                pathOptions: { borderRadius: 6, centerY: midY },
            };
        }
        if (Math.abs(dx) < 48) {
            return { type: 'smoothstep', sourceHandle: 'bottom', targetHandle: 'top', pathOptions: { borderRadius: 6, centerY: midY } };
        }
        return {
            type: 'smoothstep',
            sourceHandle: 'bottom',
            targetHandle: 'top',
            pathOptions: { borderRadius: 12, centerY: midY },
        };
    }

    return {
        type: 'smoothstep',
        sourceHandle: dx >= 0 ? 'right' : 'left',
        targetHandle: dx >= 0 ? 'left' : 'right',
        pathOptions: { borderRadius: 8, centerX: (sx + tx) / 2 },
    };
}

function buildEdges(rawEdges, graphNodes) {
    const byId = Object.fromEntries(graphNodes.map((n) => [n.id, n]));

    // Skip edges that go to/from category or room nodes (they're now legend-only)
    const filtered = rawEdges.filter((e) => {
        const src = byId[e.source];
        const tgt = byId[e.target];
        if (!src || !tgt) return false;
        return !PINNED_NODE_TYPES.has(src.type) && !PINNED_NODE_TYPES.has(tgt.type);
    });

    // Synthesise direct Floor → Asset (and Floor → top-of-uplink-chain) edges
    // to replace the removed Floor → Category → Asset chain.
    const synthetic = synthCategoryBypassEdges(byId);
    const allEdges = [...filtered, ...synthetic];
    const edgesBySource = new Map();

    for (const edge of allEdges) {
        const variant = normalizedEdgeVariant(edge, byId);
        if (variant === 'membership') continue;
        const group = edgesBySource.get(edge.source) ?? [];
        group.push(edge);
        edgesBySource.set(edge.source, group);
    }

    for (const group of edgesBySource.values()) {
        group.sort((a, b) => {
            const aNode = byId[a.target];
            const bNode = byId[b.target];
            const ax = aNode ? nodeCenterX(aNode) : 0;
            const bx = bNode ? nodeCenterX(bNode) : 0;
            if (ax !== bx) return ax - bx;
            return String(a.id).localeCompare(String(b.id));
        });
    }

    return allEdges.map((e) => {
        const variant = normalizedEdgeVariant(e, byId);
        const offline = edgeTouchesOffline(e, byId, variant);
        const sourceGroup = edgesBySource.get(e.source) ?? [];
        const routeIndex = sourceGroup.findIndex((edge) => edge.id === e.id);
        const routeOffset = sourceGroup.length > 1 && routeIndex >= 0
            ? (routeIndex - (sourceGroup.length - 1) / 2) * 18
            : 0;
        const routing = resolveRouting(e, byId, routeOffset);
        const base    = e.style ?? {};

        return {
            ...e,
            type: routing.type,
            sourceHandle: routing.sourceHandle,
            targetHandle: routing.targetHandle,
            class: offline ? 'topology-edge--offline' : e.class,
            pathOptions: routing.pathOptions ?? {},
            data: { ...(e.data ?? {}), offline, variant },
            style: {
                strokeWidth: variant === 'hosts' ? 3 : variant === 'membership' ? 1.6 : 2.5,
                ...base,
                stroke: offline
                    ? OFFLINE_EDGE_COLOR
                    : variant === 'membership'
                        ? MEMBERSHIP_EDGE_COLOR
                        : base.stroke,
                strokeDasharray: variant === 'membership' ? '4 4' : base.strokeDasharray,
                opacity: variant === 'membership' ? 0.72 : base.opacity,
            },
        };
    });
}

/**
 * Build synthetic Group → Asset edges that bypass the hidden category/room layer.
 *
 * Emit only for assets whose structural parent is hidden. Child assets that
 * already have a visible uplink parent keep only their visible uplink edge.
 */
function synthCategoryBypassEdges(byId) {
    const pendingById = Object.fromEntries(pendingLayoutNodes.value.map((n) => [n.id, n]));
    const existingPairs = new Set(
        props.initialEdges.map((e) => `${e.source}::${e.target}`),
    );
    const seen = new Set();
    const result = [];

    for (const pending of pendingLayoutNodes.value) {
        if (pending.type !== 'inventory-asset') continue;
        if (!byId[pending.id]) continue; // node not in current rendered graph

        const directParent = pending.parentId ? pendingById[pending.parentId] : null;
        if (!directParent || !PINNED_NODE_TYPES.has(directParent.type)) continue;

        let structuralGroup = null;
        let cur = directParent;
        while (cur?.parentId) {
            const parent = pendingById[cur.parentId];
            if (!parent) break;
            if (parent.type === 'floor-group' || parent.type === 'location-group') {
                structuralGroup = parent;
                break;
            }
            if (!PINNED_NODE_TYPES.has(parent.type)) break;
            cur = parent;
        }

        if (!structuralGroup || !byId[structuralGroup.id]) continue;
        if (existingPairs.has(`${structuralGroup.id}::${pending.id}`)) continue;

        const eid = `e-syn-grp-ast-${structuralGroup.id}-${pending.id}`;
        if (seen.has(eid)) continue;
        seen.add(eid);

        result.push({
            id: eid,
            source: structuralGroup.id,
            target: pending.id,
            data: { variant: 'membership' },
            style: {},
        });
    }

    return result;
}

/**
 * Sync live node positions back into pendingLayoutNodes (which carries parentId).
 * Called before commitEdges so routing uses up-to-date positions.
 */
function syncPending() {
    const liveById = Object.fromEntries(nodes.value.map((n) => [n.id, n]));
    pendingLayoutNodes.value = pendingLayoutNodes.value.map((node) => {
        const live = liveById[node.id];
        return live ? { ...node, position: { ...live.position } } : node;
    });
}

function commitEdges() {
    edges.value = buildEdges(props.initialEdges, pendingLayoutNodes.value);
}

function refreshEdges() {
    if (!graphReady.value) return;
    syncPending();
    commitEdges();
}

// ─── Group drag ───────────────────────────────────────────────────────────────

/** Collect all descendant node IDs (recursive) from pendingLayoutNodes. */
function getDescendants(nodeId) {
    const result = new Set();
    function walk(pid) {
        for (const n of pendingLayoutNodes.value) {
            if (n.parentId === pid) {
                result.add(n.id);
                walk(n.id);
            }
        }
    }
    walk(nodeId);
    return result;
}

/**
 * If the given node is the physical host for a Proxmox integration, return the
 * proxmox node ID and all its guest IDs. Used to move the virtual branch when
 * the host asset is dragged.
 */
function getVirtualGroup(nodeId) {
    const result = new Set();
    const proxmox = pendingLayoutNodes.value.find(
        (n) => n.type === 'proxmox-integration' && n.data?.hostNodeId === nodeId,
    );
    if (!proxmox) return result;

    result.add(proxmox.id);
    for (const n of pendingLayoutNodes.value) {
        if (n.type === 'proxmox-guest') result.add(n.id);
    }
    return result;
}

/**
 * Group drag behaviour:
 * - floor-group: all structural descendants follow
 * - any node that is a Proxmox host: Proxmox + guests follow
 * - site-group: moves independently (no group drag)
 */
function applyGroupDrag(draggedNode) {
    const pre = preDragSnapshot.value[draggedNode.id];
    if (!pre) return;

    const dx = draggedNode.position.x - pre.x;
    const dy = draggedNode.position.y - pre.y;
    if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;

    // Structural children (site/floor hierarchy)
    const toMove = new Set();
    if (GROUP_DRAG_TYPES.has(draggedNode.type)) {
        for (const id of getDescendants(draggedNode.id)) toMove.add(id);
    }

    // Virtual branch: Proxmox + guests follow their host asset
    for (const id of getVirtualGroup(draggedNode.id)) toMove.add(id);

    if (!toMove.size) return;

    nodes.value = nodes.value.map((n) => {
        if (!toMove.has(n.id)) return n;
        const p = preDragSnapshot.value[n.id] ?? n.position;
        return { ...n, position: { x: p.x + dx, y: p.y + dy } };
    });
}

/**
 * Re-anchor PINNED (category/room) nodes to their parent's live position.
 * This is the complement to applyGroupDrag — ensures pinned children are
 * exactly at the right offset after any drag (group or individual asset).
 */
function rePinNodes() {
    const auto = layoutHierarchy(props.initialNodes);
    const autoById    = Object.fromEntries(auto.map((n) => [n.id, n]));
    const pendingById = Object.fromEntries(pendingLayoutNodes.value.map((n) => [n.id, n]));
    const liveById    = Object.fromEntries(nodes.value.map((n) => [n.id, n]));

    nodes.value = nodes.value.map((node) => {
        if (!PINNED_NODE_TYPES.has(node.type)) return node;

        const autoNode  = autoById[node.id];
        if (!autoNode) return node;

        const parentId  = pendingById[node.id]?.parentId;
        if (!parentId) return { ...node, position: { ...autoNode.position } };

        const autoParent = autoById[parentId];
        const liveParent = liveById[parentId];
        if (!autoParent || !liveParent) return { ...node, position: { ...autoNode.position } };

        const dx = liveParent.position.x - autoParent.position.x;
        const dy = liveParent.position.y - autoParent.position.y;

        return {
            ...node,
            position: {
                x: autoNode.position.x + dx,
                y: autoNode.position.y + dy,
            },
        };
    });
}

// ─── Drag event handlers ──────────────────────────────────────────────────────

/**
 * Re-anchor the virtual branch after drag/selection changes so Proxmox stays
 * offset from its host and every VM / CT remains stacked underneath it.
 */
function rePinVirtual() {
    // In proxmox mode guests are freely draggable — no re-pinning needed.
    if (FULL_VIRTUAL_MODES.has(props.mode)) return;

    const metrics = getLayoutMetrics(pendingLayoutNodes.value.length);
    const liveById = Object.fromEntries(nodes.value.map((n) => [n.id, n]));

    const proxmoxMeta = pendingLayoutNodes.value.find((n) => isVirtualIntegration(n));
    const liveProx = proxmoxMeta ? liveById[proxmoxMeta.id] : null;
    const guests = getSortedVirtualGuests(nodes.value);
    const hostId = proxmoxMeta?.data?.hostNodeId ?? null;
    const liveHost = hostId ? liveById[hostId] : null;

    const proxmox = liveProx ?? proxmoxMeta;
    if (!proxmox) return;
    if (hostId && !liveHost) return;

    const positions = buildVirtualBranchPositions({
        proxmox,
        guests,
        metrics,
        host: liveHost,
    });

    nodes.value = nodes.value.map((node) => (
        positions[node.id]
            ? { ...node, position: { ...positions[node.id] } }
            : node
    ));
}

function snapshotPositions() {
    preDragSnapshot.value = Object.fromEntries(
        nodes.value.map((n) => [n.id, { x: n.position.x, y: n.position.y }]),
    );
}

function onNodeDragStart() {
    if (isLocked.value) return;
    snapshotPositions();
}

function onNodeDragStop({ node }) {
    if (isLocked.value) return;
    applyGroupDrag(node);
    rePinNodes();
    rePinVirtual();
    refreshEdges();
    if (!isLocked.value) persistLayout();
}

function onSelectionDragStart() {
    if (isLocked.value) return;
    snapshotPositions();
}

function onSelectionDragStop({ nodes: draggedNodes }) {
    if (isLocked.value) return;
    if (!draggedNodes?.length) return;

    // Only apply group drag for top-level dragged nodes in the hierarchy
    // to avoid double-moving descendants when both parent and child are selected.
    const draggedIds = new Set(draggedNodes.map((n) => n.id));

    for (const node of draggedNodes) {
        // Skip if any ancestor is also in the dragged set
        let cur = pendingLayoutNodes.value.find((n) => n.id === node.id);
        let dominated = false;
        while (cur?.parentId) {
            if (draggedIds.has(cur.parentId)) { dominated = true; break; }
            cur = pendingLayoutNodes.value.find((n) => n.id === cur.parentId);
        }
        if (!dominated) applyGroupDrag(node);
    }

    rePinNodes();
    rePinVirtual();
    refreshEdges();
    if (!isLocked.value) persistLayout();
}

// ─── View helpers ─────────────────────────────────────────────────────────────

function scheduleFitView() {
    const minZoom = props.initialNodes.length >= 100 ? 0.12
        : props.initialNodes.length >= 50 ? 0.2 : 0.35;

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            fitViewRef.value?.({ padding: 0.22, duration: 250, minZoom, maxZoom: 1.2 });
        });
    });
}

/**
 * onNodesInitialized fires once per VueFlow mount (including after :key remount).
 * This is the primary trigger for committing edges on initial / navigation load.
 */
function onNodesInitialized({ fitView }) {
    fitViewRef.value = fitView;
    graphReady.value = true;
    commitEdges();
    scheduleFitView();
}

// ─── Reset layout ─────────────────────────────────────────────────────────────

async function resetLayout() {
    if (!props.siteId || !canEditLayout.value) return;

    try {
        await fetch(route('topology.layout.destroy'), {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ site_id: props.siteId, mode: props.mode }),
        });
    } catch {
        // continue with local reset
    }

    layoutOverride.value = null;
    applyGraph();

    // VueFlow doesn't remount on reset (same :key) → onNodesInitialized won't fire.
    // Manually commit edges after Vue has processed the new node list.
    await nextTick();
    await nextTick();
    graphReady.value = true;
    commitEdges();
    scheduleFitView();
}

async function tidyLayout() {
    if (!props.siteId || !canEditLayout.value || isSavingLayout.value) return;

    graphReady.value = false;
    edges.value = [];
    loadLockState();

    pendingLayoutNodes.value = buildGraphNodes({
        useSavedPositions: false,
        tidy: props.mode !== 'docker',
    });
    nodes.value = toFlowNodes(pendingLayoutNodes.value);

    // Wait for Vue to flush the node array replacement into VueFlow
    await nextTick();
    await nextTick();
    graphReady.value = true;
    commitEdges();
    // Extra nextTick to let edges settle in the DOM before fitting view
    await nextTick();
    scheduleFitView();

    const saved = await persistLayout(collectPersistablePositionsFrom(nodes.value));
    if (!saved) {
        console.warn('[Topology] Tidy applied locally but positions were not persisted. They will reset on the next page load.');
    }
}

// ─── Mode / UI handlers ───────────────────────────────────────────────────────

const panOnDrag = true;

function selectMode(m) {
    router.get(route('topology.index'), { site: props.siteId, mode: m }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

async function toggleLock() {
    if (!canEditLayout.value || isSavingLayout.value) return;
    isLocked.value = !isLocked.value;
    await persistLayout(collectPositions());
    // Rebuild nodes so per-node draggable flags reflect the new lock state immediately
    nodes.value = toFlowNodes(pendingLayoutNodes.value);
}

function toggleSelectionMode() {
    selectionMode.value = !selectionMode.value;
}

function toggleFullscreen() {
    fullscreen.value = !fullscreen.value;
}

function onEscapeKey(e) {
    if (e.key === 'Escape' && fullscreen.value) fullscreen.value = false;
}

// ─── Lifecycle & watches ──────────────────────────────────────────────────────

/**
 * Watch siteId, mode and topologyLayout (all shallow).
 * NOT using `deep: true` — that was the root cause of edges disappearing:
 * deep watch would re-fire on any prop mutation, reset graphReady+edges, but
 * onNodesInitialized wouldn't re-fire → edges stay empty.
 *
 * VueFlow :key="${siteId}-${mode}" guarantees a full remount on navigation,
 * so onNodesInitialized always fires when the key changes.
 */
watch(
    () => [props.siteId, props.mode, props.topologyLayout],
    () => {
        layoutOverride.value = undefined;
        applyGraph();
    },
    { immediate: true },
);

watch(fullscreen, (active) => {
    document.body.style.overflow = active ? 'hidden' : '';
    if (active) scheduleFitView();
});

onMounted(() => window.addEventListener('keydown', onEscapeKey));
onUnmounted(() => {
    window.removeEventListener('keydown', onEscapeKey);
    document.body.style.overflow = '';
});
</script>

<template>
    <div class="topology-canvas" :class="{ 'topology-canvas--fullscreen': fullscreen }">
        <!--
            :key="${siteId}-${mode}" forces a full VueFlow remount whenever the user
            navigates to a different site or switches mode. This guarantees
            @nodes-initialized fires, which is where edges are committed.
        -->
        <VueFlow
            :key="`${siteId}-${mode}`"
            v-model:nodes="nodes"
            v-model:edges="edges"
            :node-types="nodeTypes"
            :min-zoom="0.08"
            :max-zoom="2"
            :nodes-draggable="canEditLayout && !isLocked"
            :nodes-connectable="false"
            :edges-updatable="false"
            :elements-selectable="true"
            :select-nodes-on-drag="false"
            :selection-mode="SelectionMode.Partial"
            :pan-on-drag="panOnDrag"
            :pan-on-scroll="true"
            :selection-key-code="selectionMode ? 'Shift' : null"
            :elevate-nodes-on-select="false"
            :multi-selection-key-code="['Meta', 'Control']"
            :fit-view-on-init="false"
            class="topology-flow"
            :class="{
                'topology-flow--locked': isLocked,
                'topology-flow--select-mode': selectionMode,
                'topology-flow--fullscreen': fullscreen,
            }"
            @nodes-initialized="onNodesInitialized"
            @node-drag-start="onNodeDragStart"
            @node-drag-stop="onNodeDragStop"
            @selection-drag-start="onSelectionDragStart"
            @selection-drag-stop="onSelectionDragStop"
        >
            <Background :gap="24" :size="1" pattern-color="#2B3139" />
            <Controls position="bottom-right" />
            <MiniMap
                position="bottom-left"
                :pannable="true"
                :zoomable="true"
                node-stroke-color="#707A8A"
                node-color="#1E2329"
                mask-color="rgba(11, 14, 17, 0.75)"
                style="background-color: #1E2329;"
            />

            <Panel position="top-right" class="topology-controls">
                <!-- Legend — above toolbar -->
                <div class="topology-legend">
                    <button
                        type="button"
                        class="topology-legend__toggle"
                        :title="legendOpen ? 'Hide legend' : 'Show legend'"
                        @click="legendOpen = !legendOpen"
                    >
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4M12 8h.01" />
                        </svg>
                        <span class="topology-legend__toggle-label">Legend</span>
                        <svg
                            width="10" height="10" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5"
                            :style="{ transform: legendOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.18s' }"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
                        </svg>
                    </button>

                    <div v-if="legendOpen" class="topology-legend__body">
                        <div class="topology-legend__section-label">Layers</div>

                        <template v-if="mode === 'network'">
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--network" />
                                Uplink / network path
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--membership" />
                                Floor / room membership
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--offline" />
                                Offline link
                            </div>
                            <div class="topology-legend__note">
                                First load uses role-aware auto layout. Set location as <strong>Lantai 1 / Ruang A</strong> and uplink on each asset in Inventory.
                            </div>
                        </template>
                        <template v-else-if="mode === 'proxmox'">
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--virtual" />
                                Proxmox integration
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--virtual" />
                                VM / CT workloads
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--offline" />
                                Offline link
                            </div>
                            <div v-if="graphMeta.hostAssetName" class="topology-legend__note">
                                Host: {{ graphMeta.hostAssetName }}
                            </div>
                            <div v-else-if="!graphMeta.hasIntegration" class="topology-legend__note">
                                No Proxmox integration for this site.
                            </div>
                        </template>
                        <template v-else-if="mode === 'docker'">
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--docker" />
                                Docker integration
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--docker" />
                                Container workloads
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--offline" />
                                Stopped container
                            </div>
                            <div v-if="!graphMeta.hasIntegration" class="topology-legend__note">
                                No Docker integration for this site.
                            </div>
                        </template>
                        <template v-else-if="mode === 'nvr'">
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--nvr" />
                                NVR integration
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--nvr" />
                                CCTV camera stream
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--offline" />
                                Disabled camera
                            </div>
                            <div class="topology-legend__note">
                                Only main stream channels are shown. Sub-stream channel 02 is hidden.
                            </div>
                            <div v-if="!graphMeta.hasIntegration" class="topology-legend__note">
                                No NVR integration for this site.
                            </div>
                        </template>
                        <template v-else>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--physical" />
                                Physical assets
                            </div>
                            <div class="topology-legend__item">
                                <span class="topology-legend__swatch topology-legend__swatch--offline" />
                                Offline link
                            </div>
                        </template>

                        <!-- Category / Room legend -->
                        <template v-if="hiddenGroupLabels.length">
                            <div class="topology-legend__divider" />
                            <div class="topology-legend__section-label">{{ mode === 'network' ? 'Rooms' : 'Categories' }}</div>
                            <div
                                v-for="group in hiddenGroupLabels"
                                :key="`${group.type}-${group.floor}-${group.label}`"
                                class="topology-legend__item topology-legend__item--category"
                            >
                                <span class="topology-legend__swatch topology-legend__swatch--category" />
                                {{ group.label }}
                                <span v-if="group.floor" class="topology-legend__cat-floor">· {{ group.floor }}</span>
                            </div>
                        </template>

                        <div class="topology-legend__hint">
                            <template v-if="!canEditLayout">Read-only layout.</template>
                            <template v-else-if="isLocked">Nodes locked — unlock to rearrange.</template>
                            <template v-else-if="selectionMode">Shift + drag to select.</template>
                            <template v-else-if="mode === 'docker'">Site â†’ Docker host â†’ containers.</template>
                            <template v-else-if="mode === 'nvr'">Site → NVR → cameras.</template>
                            <template v-else-if="mode === 'network'">Site → Floor → room → devices.</template>
                            <template v-else-if="mode === 'proxmox'">Site → Proxmox → VM/CT.</template>
                            <template v-else>Site → Location → devices.</template>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="topology-toolbar">
                    <button
                        type="button"
                        class="topology-toolbar__btn"
                        :class="{ 'topology-toolbar__btn--active': selectionMode }"
                        title="Hold Shift + drag to select multiple nodes (Ctrl/Cmd+click to add)"
                        @click="toggleSelectionMode"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" d="M4 4h16v16H4z" stroke-dasharray="3 2" />
                        </svg>
                        Select
                    </button>
                    <button
                        v-if="canEditLayout"
                        type="button"
                        class="topology-toolbar__btn"
                        :class="{ 'topology-toolbar__btn--active': isLocked }"
                        :disabled="isSavingLayout"
                        :title="isLocked ? 'Unlock nodes to move them' : 'Lock nodes in place'"
                        @click="toggleLock"
                    >
                        <svg v-if="isLocked" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <rect x="5" y="11" width="14" height="10" rx="2" />
                            <path stroke-linecap="round" d="M8 11V8a4 4 0 118 0v3" />
                        </svg>
                        <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <rect x="5" y="11" width="14" height="10" rx="2" />
                            <path stroke-linecap="round" d="M8 11V8a4 4 0 017.78-1" />
                        </svg>
                        {{ isLocked ? 'Locked' : 'Lock' }}
                    </button>
                    <button
                        v-if="canEditLayout && mode !== 'proxmox'"
                        type="button"
                        class="topology-toolbar__btn"
                        :disabled="isSavingLayout"
                        title="Rebuild layout to spread child nodes and reduce edge overlap"
                        @click="tidyLayout"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h8M4 12h16M4 17h10" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 5l4 4-4 4" />
                        </svg>
                        Tidy
                    </button>
                    <button
                        v-if="canEditLayout"
                        type="button"
                        class="topology-toolbar__btn"
                        title="Reset to auto layout"
                        @click="resetLayout"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M20 20v-6h-6M20 8a8 8 0 00-14.5-3M4 16a8 8 0 0014.5 3" />
                        </svg>
                        Reset
                    </button>
                    <button
                        type="button"
                        class="topology-toolbar__btn"
                        :class="{ 'topology-toolbar__btn--active': fullscreen }"
                        :title="fullscreen ? 'Exit full canvas (Esc)' : 'Expand canvas to full screen'"
                        @click="toggleFullscreen"
                    >
                        <svg v-if="fullscreen" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 14h6v6M14 4h6v6M20 10v4M10 20h4" />
                        </svg>
                        <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 00-2 2v3M21 8V5a2 2 0 00-2-2h-3M16 21h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3" />
                        </svg>
                        {{ fullscreen ? 'Exit full' : 'Full' }}
                    </button>
                </div>

                <div class="topology-modes">
                    <button
                        v-for="modeOption in MODES"
                        :key="modeOption.id"
                        type="button"
                        class="topology-modes__btn"
                        :class="{ 'topology-modes__btn--active': modeOption.id === mode }"
                        @click="selectMode(modeOption.id)"
                    >
                        {{ modeOption.label }}
                    </button>
                </div>
            </Panel>
        </VueFlow>
    </div>
</template>

<style>
.topology-canvas {
    height: calc(100vh - 168px);
    width: 100%;
    background-color: var(--color-canvas, #0B0E11);
}

.topology-canvas--fullscreen {
    position: fixed;
    inset: 0;
    z-index: 100;
    height: 100vh;
    width: 100vw;
}

.topology-flow {
    --vf-node-bg: var(--color-card, #1E2329);
    --vf-node-color: var(--color-body, #EAECEF);
    --vf-node-border: var(--color-hairline, #2B3139);
    --vf-handle: var(--color-muted, #707A8A);
    --vf-box-shadow: none;
    --vf-selection: rgba(252, 213, 53, 0.12);
    --vf-edge-stroke: #707A8A;
}

/* ─── Legend ───────────────────────────────────────────────────────────────── */

.topology-legend {
    background: color-mix(in oklab, var(--color-card, #1E2329) 94%, transparent);
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 8px;
    font-family: var(--font-display);
    font-size: 11px;
    color: var(--color-muted, #707A8A);
    min-width: 176px;
    overflow: hidden;
}

.topology-legend__toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 7px 11px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--color-body, #EAECEF);
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-align: left;
}

.topology-legend__toggle:hover {
    background: color-mix(in oklab, var(--color-hairline, #2B3139) 40%, transparent);
}

.topology-legend__toggle-label {
    flex: 1;
}

.topology-legend__body {
    padding: 4px 11px 10px;
    border-top: 1px solid var(--color-hairline, #2B3139);
}

.topology-legend__section-label {
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--color-muted, #707A8A);
    margin-bottom: 6px;
    margin-top: 4px;
}

.topology-legend__item {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 5px;
    color: var(--color-body, #EAECEF);
}

.topology-legend__swatch {
    width: 9px;
    height: 9px;
    border-radius: 2px;
    flex-shrink: 0;
}

.topology-legend__swatch--physical  { background: #707A8A; }
.topology-legend__swatch--virtual   { background: #F0B90B; }
.topology-legend__swatch--docker    { background: #2496ED; }
.topology-legend__swatch--nvr       { background: #F59E0B; }
.topology-legend__swatch--network   { background: #3B82F6; }
.topology-legend__swatch--membership { background: #707A8A; }
.topology-legend__swatch--offline   { background: #F6465D; }
.topology-legend__swatch--category  { background: #4B5563; border-radius: 2px; }

.topology-legend__note {
    margin-top: 8px;
    padding-top: 7px;
    border-top: 1px solid var(--color-hairline, #2B3139);
    font-size: 10px;
    line-height: 1.4;
    color: var(--color-muted, #707A8A);
}

.topology-legend__note strong {
    color: var(--color-body, #EAECEF);
    font-weight: 600;
}

.topology-legend__divider {
    margin: 7px 0;
    border-top: 1px solid var(--color-hairline, #2B3139);
}

.topology-legend__item--category {
    color: var(--color-body, #EAECEF);
}

.topology-legend__cat-floor {
    color: var(--color-muted, #707A8A);
    font-size: 10px;
}

.topology-legend__hint {
    margin-top: 8px;
    padding-top: 7px;
    border-top: 1px solid var(--color-hairline, #2B3139);
    font-size: 10px;
    line-height: 1.4;
    color: var(--color-muted, #707A8A);
}

/* ─── Toolbar / controls ───────────────────────────────────────────────────── */

.topology-controls {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    margin: 12px;
}

.topology-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 6px;
}

.topology-modes {
    display: flex;
    gap: 6px;
}

.topology-modes__btn {
    padding: 5px 12px;
    background: var(--color-card, #1E2329);
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 6px;
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
}

.topology-modes__btn:hover {
    background: var(--color-elevated, #2B3139);
    color: var(--color-body, #EAECEF);
}

.topology-modes__btn--active {
    border-color: #3B82F6;
    color: var(--color-body, #EAECEF);
    box-shadow: 0 0 0 2px color-mix(in oklab, #3B82F6 16%, transparent);
}

.topology-toolbar__btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: var(--color-card, #1E2329);
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 6px;
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
}

.topology-toolbar__btn:hover {
    background: var(--color-elevated, #2B3139);
    color: var(--color-body, #EAECEF);
}

.topology-toolbar__btn:disabled {
    cursor: wait;
    opacity: 0.58;
}

.topology-toolbar__btn--active {
    border-color: var(--color-primary, #FCD535);
    color: var(--color-body, #EAECEF);
    box-shadow: 0 0 0 2px color-mix(in oklab, var(--color-primary, #FCD535) 16%, transparent);
}

/* ─── Node states ──────────────────────────────────────────────────────────── */

.topology-flow--locked { cursor: default; }

.topology-flow--select-mode .vue-flow__pane { cursor: crosshair; }

.topology-flow .vue-flow__node.selected {
    box-shadow: 0 0 0 2px color-mix(in oklab, var(--color-primary, #FCD535) 40%, transparent);
}

.topology-flow--locked .vue-flow__node {
    cursor: default;
    pointer-events: none;
}

.topology-flow .topology-node--pinned {
    cursor: default;
    pointer-events: none;
}

.topology-flow .topology-node--pinned .vue-flow__handle { display: none; }

/* ─── Handles ──────────────────────────────────────────────────────────────── */

.vue-flow__handle {
    width: 6px;
    height: 6px;
    background: var(--color-muted, #707A8A) !important;
    border: 1px solid var(--color-hairline, #2B3139) !important;
    opacity: 0;
    pointer-events: none;
}

/* ─── Edges ────────────────────────────────────────────────────────────────── */

.vue-flow__edge-path {
    stroke-width: 2.5;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.topology-flow .topology-edge--offline .vue-flow__edge-path {
    stroke: #F6465D !important;
}

.topology-flow .topology-edge--offline.animated path {
    stroke: #F6465D !important;
}

.vue-flow__edge.animated path {
    stroke-dasharray: 6 4;
    animation: topology-edge-flow 0.8s linear infinite;
}

@keyframes topology-edge-flow {
    to { stroke-dashoffset: -20; }
}

/* ─── MiniMap / Controls ───────────────────────────────────────────────────── */

.vue-flow__minimap {
    border: 1px solid var(--color-hairline, #2B3139) !important;
    border-radius: 8px !important;
    overflow: hidden !important;
}

.vue-flow__controls-button {
    background: var(--color-card, #1E2329) !important;
    border: 1px solid var(--color-hairline, #2B3139) !important;
    color: var(--color-body, #EAECEF) !important;
    fill: var(--color-body, #EAECEF) !important;
}

.vue-flow__controls-button:hover {
    background: var(--color-elevated, #2B3139) !important;
}
</style>
