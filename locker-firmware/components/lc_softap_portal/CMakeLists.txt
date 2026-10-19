idf_component_register(
  SRCS portal.c
  INCLUDE_DIRS include
  REQUIRES lc_domain
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
