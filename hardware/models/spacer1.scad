// Abstandshalter für Electric Solenoid Lock FIT0620

use <fillets3d.scad>;   // https://github.com/ademuri/openscad-fillets

// -----------------------------
// Parameter
// -----------------------------

// Abmessungen Schloss
lock_len        = 73;       // Länge Schloss
lock_width      = 58;       // Höhe Schloss
spacer_height   = 34;       // Höhe des Spacers
notch_length    = 25;       // Länge Aussparung
notch_width     = 8;        // Breite Aussparung

hole_diameter   = 3.2;      // Durchmesser Schraubenloch
hole2_diameter  = 5.5;      // Durchmesser Gewinde Nietmutter
hole2_height    = 7;        // Höhe Gewinde Nietmutter
hole3_diameter  = 8;        // Durchmesser Kragen Nietmutter
hole3_height    = .5;       // Höhe Kragen Nietmutter

hole_offset_x1      = 6.5;
hole_offset_x2      = 36;
hole_offset_y1      = 36.5;

// -----------------------------
// 📦 Hauptmodul
// -----------------------------
module spacer() {
 $fn=50;

// use the following line to smoothen. caution! will take some time to render!
// topBottomFillet(b = 0, t = spacer_height, r = 1, s = 10, e=1)    

    difference(){
        cube([lock_len, lock_width, spacer_height], center=false);
        
        translate([-1, (lock_width - notch_width)/2, -1])
        cube([notch_length+1, notch_width, spacer_height+2], center=false);
        
        translate([hole_offset_x1, (lock_width + hole_offset_y1)/2, -1])
        cylinder(spacer_height+2, d = hole_diameter);
        translate([hole_offset_x1, (lock_width + hole_offset_y1)/2, -1])
        cylinder(hole2_height+1, d = hole2_diameter);
        translate([hole_offset_x1, (lock_width + hole_offset_y1)/2, -1])
        cylinder(hole3_height+1, d = hole3_diameter);
        

        translate([hole_offset_x1, (lock_width - hole_offset_y1)/2, -1])
        cylinder(spacer_height+2, d = hole_diameter);
        translate([hole_offset_x1, (lock_width - hole_offset_y1)/2, -1])
        cylinder(hole2_height+1, d = hole2_diameter);
        translate([hole_offset_x1, (lock_width - hole_offset_y1)/2, -1])
        cylinder(hole3_height+1, d = hole3_diameter);

        translate([hole_offset_x1 + hole_offset_x2, (lock_width)/2, -1])
        cylinder(spacer_height+2, d = hole_diameter);
        translate([hole_offset_x1 + hole_offset_x2, (lock_width)/2, -1])
        cylinder(hole2_height+1, d = hole2_diameter);
        translate([hole_offset_x1 + hole_offset_x2, (lock_width)/2, -1])
        cylinder(hole3_height+1, d = hole3_diameter);
        
    }
}

// -----------------------------
// ▶️ Ausgabe
// -----------------------------
spacer();