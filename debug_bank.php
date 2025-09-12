<?php

require_once 'vendor/autoload.php';

// Test what class mServer\Bank actually is
echo "Testing mServer\\Bank class resolution:\n";

if (class_exists('\\mServer\\Bank')) {
    echo "✓ \\mServer\\Bank exists\n";
    $reflection = new ReflectionClass('\\mServer\\Bank');
    echo "File: " . $reflection->getFileName() . "\n";
    
    $bank = new \mServer\Bank();
    echo "Instance of: " . get_class($bank) . "\n";
    
    // Check if setDataValue method exists
    if (method_exists($bank, 'setDataValue')) {
        echo "✓ setDataValue method exists\n";
    } else {
        echo "✗ setDataValue method does NOT exist\n";
    }
    
    // List all methods
    echo "Available methods:\n";
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($methods as $method) {
        if ($method->getDeclaringClass()->getName() === '\\mServer\\Bank' || 
            strpos($method->getName(), 'setData') !== false ||
            strpos($method->getName(), 'Data') !== false ||
            strpos($method->getName(), 'currency') !== false) {
            echo "  - " . $method->getName() . "\n";
        }
    }
} else {
    echo "✗ \\mServer\\Bank does NOT exist\n";
}

// Check if there are any riesenia pohoda classes loaded
echo "\nChecking for riesenia/pohoda classes:\n";
if (class_exists('\\Riesenia\\Pohoda\\Bank')) {
    echo "✓ \\Riesenia\\Pohoda\\Bank exists\n";
} else {
    echo "✗ \\Riesenia\\Pohoda\\Bank does NOT exist\n";
}

// Show all loaded classes
echo "\nLoaded Bank-related classes:\n";
$classes = get_declared_classes();
foreach ($classes as $class) {
    if (strpos($class, 'Bank') !== false) {
        echo "  - $class\n";
    }
}
