idf_component_register(
  SRCS button.c
  INCLUDE_DIRS include
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
