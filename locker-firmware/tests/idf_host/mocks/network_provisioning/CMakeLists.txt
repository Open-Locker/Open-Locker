# Replaces espressif/network_provisioning with a CMock mock generated from its
# real header. The header comes from the firmware's resolved copy
# (dependencies.lock), so build the firmware first. Linux target only.
set(prov_dir "${CMAKE_CURRENT_LIST_DIR}/../../../../managed_components/espressif__network_provisioning")
if(NOT EXISTS "${prov_dir}/include/network_provisioning/network_config.h")
  message(FATAL_ERROR "${prov_dir} is missing: run `idf.py reconfigure` in locker-firmware first.")
endif()

idf_component_mock(INCLUDE_DIRS "${prov_dir}/include" "${prov_dir}/include/network_provisioning"
                   MOCK_HEADER_FILES ${prov_dir}/include/network_provisioning/network_config.h
                   REQUIRES esp_netif protocomm
                   )
