idf_component_register(
  SRCS app.c handle_command.c open_compartment.c apply_config.c update_firmware.c publish_state.c
       provision_device.c commission_device.c boot_mode.c
  INCLUDE_DIRS include
  REQUIRES lc_domain lc_ports
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
