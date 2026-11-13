# Replaces ESP-IDF's protocomm, which has no Linux build, with a CMock mock of
# the calls the setup network adapter makes. Linux target only.
set(protocomm_dir "$ENV{IDF_PATH}/components/protocomm")
idf_component_mock(INCLUDE_DIRS "${protocomm_dir}/include/common"
                                "${protocomm_dir}/include/security"
                                "${protocomm_dir}/include/transports"
                                "${protocomm_dir}/include/crypto/srp6a"
                   MOCK_HEADER_FILES ${protocomm_dir}/include/common/protocomm.h
                                     ${protocomm_dir}/include/transports/protocomm_httpd.h
                                     ${protocomm_dir}/include/crypto/srp6a/esp_srp.h
                   REQUIRES esp_event freertos
                   )
