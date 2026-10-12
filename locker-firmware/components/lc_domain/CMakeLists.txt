idf_component_register(
  SRCS config.c door_detection.c
  INCLUDE_DIRS include
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
