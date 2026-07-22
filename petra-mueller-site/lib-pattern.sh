pattern_content() {
  local name="$1"
  $WP eval "\$p = include get_template_directory() . '/patterns/' . '${name}' . '.php'; echo \$p['content'];"
}
