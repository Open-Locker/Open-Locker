# Replaces app_update (esp_ota_ops) with a CMock mock generated from its real header
# (the real component is not built for the Linux target). Linux target only.
# bootloader_support is not built for Linux either, but its headers are all the
# declarations here need.
set(update_dir "$ENV{IDF_PATH}/components/app_update")

idf_component_mock(INCLUDE_DIRS "${update_dir}/include"
                                "$ENV{IDF_PATH}/components/bootloader_support/include"
                                "$ENV{IDF_PATH}/components/esp_bootloader_format/include"
                   MOCK_HEADER_FILES ${update_dir}/include/esp_ota_ops.h
                   REQUIRES esp_partition esp_app_format
                   )

# CMock does not read the #if around esp_ota_revoke_secure_boot_public_key(), so it mocks a
# function whose type the Linux build leaves out. Turn the guard on for the mock and its users.
target_compile_definitions(${COMPONENT_LIB} PUBLIC CONFIG_SECURE_BOOT_V2_ENABLED=1)
