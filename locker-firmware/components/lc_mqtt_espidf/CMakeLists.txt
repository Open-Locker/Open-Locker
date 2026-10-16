idf_component_register(
  SRCS transport.c
  INCLUDE_DIRS include
  PRIV_REQUIRES mqtt esp-tls mbedtls
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
