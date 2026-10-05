<?php
// app/services/Cpms2OverheadMigrationExportService.php
// PHP 5.6. Overhead-only orchestration; source recognition stays in Cpms2OverheadExportService.
require_once __DIR__.'/Cpms2OverheadExportService.php';
require_once __DIR__.'/Cpms2OverheadPackageWriter.php';
class Cpms2OverheadMigrationExportService
{
    private $source; private $root; private $storage;
    public function __construct($source,$root,$storage) { $this->source=$source; $this->root=$root; $this->storage=$storage; }
    public function build($output,$stage,$manifest,$cutoff=null)
    {
        $support=new Cpms2ManagementPreflightSupport();
        $exporter=new Cpms2OverheadExportService($this->source,$this->root,$this->storage,$support,$cutoff);
        $writer=null;
        try {
            $plan=$exporter->exportPlan(); $exporter->assertSourceUnchanged();
            $writer=new Cpms2OverheadPackageWriter($stage); $summary=$writer->finish($output,$plan,$manifest);
            $exporter->assertSourceUnchanged(); return $summary;
        } catch (Exception $e) {
            if (is_file($output)) unlink($output);
            throw $e;
        } finally {
            if ($writer) $writer->cleanup();
        }
    }
}
