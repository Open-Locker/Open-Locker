// Verschlussstopfen für Metallschränke

use <fillets3d.scad>;   // https://github.com/ademuri/openscad-fillets

// -----------------------------
// Parameter
// -----------------------------

// Abmessungen
plate_diameter  = 35;       // 
plate_height    = 1.0;      // 

hole_diameter = 19.9;   // 20
hole_width = 17.4;      // 17.5
hole_thinkness = 1;     // 1

total_height = 4;

// -----------------------------
// 📦 Hauptmodul
// -----------------------------
module cap() {
 $fn=150;

// use the following line to smoothen. caution! will take some time to render!
// topBottomFillet(b = 0, t = spacer_height, r = .5, s = 10, e=1)

    cylinder(plate_height, d = plate_diameter);
    translate ([0,0,plate_height])
    difference(){
        cylinder(total_height, d = hole_diameter);
        translate([hole_width / 2, -10, 0])
        cube([20, 20, total_height + .1]);
        translate([-(20 + hole_width / 2), -10, 0])
        cube([20, 20, total_height + .1 ]);
        
        translate([-25, 3, hole_thinkness + 1])
        rotate ([0,90,0])
        cylinder(50, d = 1.9);
        
        translate([-25, -3, hole_thinkness + 1])
        rotate ([0,90,0])
        cylinder(50, d = 1.9);        
    }
}

// -----------------------------
// ▶️ Ausgabe
// -----------------------------
cap();