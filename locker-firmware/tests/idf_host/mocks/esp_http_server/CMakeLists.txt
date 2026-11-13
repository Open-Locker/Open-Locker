# The setup network adapter requires esp_http_server only for protocomm's HTTP
# transport, which is mocked. This empty stand-in keeps the real server, which
# does not build against the mocked lwip, out of the Linux build.
idf_component_register()
