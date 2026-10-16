idf_component_register(
  SRCS line.c console.c setup_json.c console_task.c
  INCLUDE_DIRS include
  REQUIRES lc_domain lc_ports
  PRIV_REQUIRES driver json
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
