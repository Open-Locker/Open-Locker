idf_component_register(
  SRCS codec.c bus.c self_test.c
  INCLUDE_DIRS include
  REQUIRES lc_domain lc_ports
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
