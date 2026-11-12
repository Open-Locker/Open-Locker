# Replaces esp_https_ota with a CMock mock generated from its real header (the real
# component is not built for the Linux target). Linux target only.
# bootloader_support is not built for Linux either, but its headers are all the
# declarations here need.
set(ota_dir "$ENV{IDF_PATH}/components/esp_https_ota")

idf_component_mock(INCLUDE_DIRS "${ota_dir}/include"
                                "$ENV{IDF_PATH}/components/bootloader_support/include"
                                "$ENV{IDF_PATH}/components/esp_bootloader_format/include"
                   MOCK_HEADER_FILES ${ota_dir}/include/esp_https_ota.h
                   REQUIRES esp_http_client esp_event esp_partition esp_app_format
                   )
