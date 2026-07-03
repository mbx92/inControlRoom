export function createHeartbeatPayloadFactory() {
  return {
    createEnrollRequest(configuration, device) {
      return {
        enroll_token: configuration.enrollmentToken ?? '',
        device_id: device.deviceId,
        hostname: device.hostname,
        os: device.os,
        os_version: device.osVersion,
        arch: device.arch,
        agent_version: device.agentVersion,
        primary_ip: device.primaryIp,
      };
    },

    createHeartbeatRequest(device, inventory) {
      const metrics = inventory.metrics;

      return {
        agent_version: device.agentVersion,
        device_id: device.deviceId,
        hostname: device.hostname,
        os: device.os,
        os_version: device.osVersion,
        arch: device.arch,
        primary_ip: device.primaryIp,
        timestamp: new Date().toISOString(),
        metrics: {
          cpu: {
            usage_percent: metrics.cpuUsagePercent,
            brand: metrics.cpuBrand,
            manufacturer: metrics.cpuManufacturer,
            cores: metrics.cpuCores,
            physical_cores: metrics.cpuPhysicalCores,
          },
          memory: {
            total_bytes: metrics.totalMemoryBytes,
            free_bytes: metrics.freeMemoryBytes,
            slots_used: metrics.memorySlotsUsed ?? 0,
            slots: (metrics.memorySlots ?? []).map((slot) => ({
              slot: slot.slot,
              size_bytes: slot.sizeBytes,
              type: slot.type,
              speed_mhz: slot.speedMhz,
              manufacturer: slot.manufacturer,
              part_number: slot.partNumber,
              form_factor: slot.formFactor,
              serial_number: slot.serialNumber,
            })),
          },
          disks: (metrics.disks ?? []).map((disk) => ({
            name: disk.name,
            total_bytes: disk.totalBytes,
            free_bytes: disk.freeBytes,
            filesystem: disk.filesystem,
            type: disk.type,
          })),
          storage_devices: (metrics.storageDevices ?? []).map((device) => ({
            device: device.device,
            name: device.name,
            type: device.type,
            vendor: device.vendor,
            size_bytes: device.sizeBytes,
            interface_type: device.interfaceType,
            serial_number: device.serialNumber,
            firmware_revision: device.firmwareRevision,
            smart_status: device.smartStatus,
          })),
          network: (metrics.network ?? []).map((entry) => ({
            iface: entry.iface,
            operstate: entry.operstate,
            mac: entry.mac,
            ipv4: entry.ipv4,
            speed_mbps: entry.speedMbps,
            rx_bytes: entry.rxBytes,
            tx_bytes: entry.txBytes,
            rx_errors: entry.rxErrors,
            tx_errors: entry.txErrors,
            rx_dropped: entry.rxDropped,
            tx_dropped: entry.txDropped,
          })),
          usb_devices: (metrics.usbDevices ?? []).map((device) => ({
            name: device.name,
            type: device.type,
            vendor: device.vendor,
            manufacturer: device.manufacturer,
            device_id: device.deviceId,
            serial_number: device.serialNumber,
          })),
        },
        services: inventory.services.map((service) => ({
          name: service.name,
          display_name: service.displayName,
          status: service.status,
          start_mode: service.startMode,
        })),
        labels: inventory.labels,
      };
    },
  };
}
