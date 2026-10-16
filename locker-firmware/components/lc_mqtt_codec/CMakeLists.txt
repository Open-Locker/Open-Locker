idf_component_register(
  SRCS codec.c topics.c provisioning.c events.c
  INCLUDE_DIRS include
  REQUIRES lc_domain lc_ports json
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
