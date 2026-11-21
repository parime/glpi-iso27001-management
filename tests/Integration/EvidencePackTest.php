<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\EvidencePack;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidencePackRules as Rules;

/**
 * Issue #868 : dossier de preuves exportable pour l'auditeur (archive ZIP par périmètre).
 */
final class EvidencePackTest extends GrcmanagerIntegrationTestCase
{
    private function rootControl(): int
    {
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => 'A.5.1', 'entities_id' => 0]));

        return (int) $control->getID();
    }

    private function attachedDocument(int $controlId, string $name, string $content, int $entityId = 0): int
    {
        $file = 'pack-test-' . uniqid() . '.txt';
        file_put_contents(GLPI_TMP_DIR . '/' . $file, $content);
        $documentsId = (int) (new \Document())->add([
            'name'          => $name,
            'entities_id'   => $entityId,
            'is_recursive'  => 0,
            '_filename'     => [$file],
            '_tag_filename' => [$file],
        ]);
        $this->assertGreaterThan(0, $documentsId);
        $this->assertGreaterThan(0, (int) (new \Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => Control::class,
            'items_id'     => $controlId,
        ]));

        return $documentsId;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string> id du document => état
     */
    private function statesOf(array $data, int $controlId): array
    {
        $code = (new Control());
        $code->getFromDB($controlId);
        $states = [];
        foreach ($data['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                if ($group['key'] === 'controls' && $row['code'] === $code->fields['code']) {
                    foreach ($row['links'] as $link) {
                        $states[$link['documents_id']] = $link['state'];
                    }
                }
            }
        }

        return $states;
    }

    public function testPackHoldsReadableValidEvidenceWithFingerprints(): void
    {
        global $DB;

        $controlId = $this->rootControl();
        $valid = $this->attachedDocument($controlId, 'Fictive valid evidence', "valid evidence\n");
        $expired = $this->attachedDocument($controlId, 'Fictive expired evidence', "expired evidence\n");
        $DB->insert('glpi_plugin_grcmanager_evidences', [
            'itemtype'     => Control::class,
            'items_id'     => $controlId,
            'documents_id' => $expired,
            'valid_until'  => '2020-01-31',
        ]);
        // Document d'une entité hors de la session : jamais lu, jamais nommé.
        $other = $this->createTestEntity(0, 'Fictive other entity');
        $hidden = $this->attachedDocument($controlId, 'Fictive hidden evidence', "hidden\n", $other);
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        $states = $this->statesOf(EvidencePack::collect(0), $controlId);
        $this->assertSame(Rules::STATE_INCLUDED, $states[$valid] ?? null);
        $this->assertSame(Rules::STATE_EXPIRED, $states[$expired] ?? null);
        $this->assertArrayNotHasKey($hidden, $states);

        $result = EvidencePack::generate(0);
        $this->assertSame('', $result['error']);
        $row = $DB->request(['FROM' => EvidencePack::TABLE, 'WHERE' => ['id' => $result['id']]])->current();
        $this->assertNotNull($row);
        $document = new \Document();
        $this->assertTrue($document->getFromDB((int) $row['documents_id']));
        $zipPath = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        $this->assertSame(hash_file('sha256', $zipPath), $row['sha256']);
        $this->assertSame(
            ['itemtype' => EvidencePack::class, 'items_id' => (int) $result['id']],
            RestrictedDocument::sourceOf((int) $row['documents_id'])
        );
        $this->assertTrue(EvidencePack::canRead((int) $result['id']), 'Readable by its author.');

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $validFile = new \Document();
        $validFile->getFromDB($valid);
        $entry = Rules::entryName($valid, (string) $validFile->fields['filename']);
        $this->assertNotFalse($zip->locateName('index.pdf'));
        $this->assertSame("valid evidence\n", $zip->getFromName($entry));
        $sums = (string) $zip->getFromName('SHA256SUMS.txt');
        $this->assertStringContainsString(hash('sha256', "valid evidence\n") . '  ' . $entry, $sums);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('evidence/' . $expired . '-', $name, 'Expired evidence not included.');
            $this->assertStringStartsNotWith('evidence/' . $hidden . '-', $name, 'Unreadable evidence not included.');
        }
        $this->assertContains((int) $result['id'], array_column(EvidencePack::history(), 'id'));
    }

    public function testSizeLimitKeepsLargeFilesOut(): void
    {
        $controlId = $this->rootControl();
        EvidencePack::saveMaxMb(1);
        $this->assertSame(1, EvidencePack::getMaxMb());
        $large = $this->attachedDocument($controlId, 'Fictive large evidence', str_repeat('x', 1100000));

        $states = $this->statesOf(EvidencePack::collect(0), $controlId);
        $this->assertSame(Rules::STATE_TOO_LARGE, $states[$large] ?? null);
    }

    public function testPerimeterOutsideTheSessionIsRefused(): void
    {
        $result = EvidencePack::generate(987654321);
        $this->assertSame(0, $result['id']);
        $this->assertNotSame('', $result['error']);
    }
}
