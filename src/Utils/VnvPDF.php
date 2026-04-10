<?php

namespace App\Utils;

class VnvPDF extends Pdf
{
    private array $institution = [];
    private string $defaultLogoPath = 'assets/images/default-logo.png';

    public function __construct(array $institution = [])
    {
        parent::__construct();
        $this->institution = $institution;
        $this->SetCreator('Ophyra');
        $this->SetAuthor('VNV System');
        $this->SetMargins(15, 25, 15);
    }

    public function Header()
    {
        $this->handleLogo();

        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(40, 10);
        $this->Cell(0, 5, $this->getInstitutionField('company_name'), 0, 1);

        $this->SetX(40);
        $address = implode(', ', array_filter([
            $this->getInstitutionField('address_line1'),
            $this->getInstitutionField('city'),
            $this->getInstitutionField('state'),
            $this->getInstitutionField('zip')
        ]));
        $this->Cell(0, 5, $address, 0, 1);

        $this->SetX(40);
        $this->Cell(0, 5, $this->getInstitutionField('phone'), 0, 1);

        $this->SetX(40);
        $this->Cell(0, 5, $this->getInstitutionField('email'), 0, 1);

        $this->SetY(30);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(10);
    }

    protected function handleLogo(): void
    {
        $logoPath = $this->getInstitutionField('logo_path');
        $logoX = 10;
        $logoY = 8;
        $logoWidth = 20;

        try {
            $tempFile = null;

            if (!empty($logoPath)) {
                if (filter_var($logoPath, FILTER_VALIDATE_URL)) {
                    $tempFile = $this->downloadRemoteImage($logoPath);
                } elseif (file_exists($logoPath)) {
                    $tempFile = $logoPath;
                }
            }

            if ($tempFile && file_exists($tempFile)) {
                $this->Image($tempFile, $logoX, $logoY, $logoWidth);
                if ($tempFile !== $logoPath && strpos($tempFile, sys_get_temp_dir()) !== false) {
                    unlink($tempFile); 
                }
            } elseif (file_exists($this->defaultLogoPath)) {
                $this->Image($this->defaultLogoPath, $logoX, $logoY, $logoWidth);
            }
        } catch (\Exception $e) {
            error_log('Error loading logo: ' . $e->getMessage());
            if (file_exists($this->defaultLogoPath)) {
                $this->Image($this->defaultLogoPath, $logoX, $logoY, $logoWidth);
            }
        }
    }

    protected function downloadRemoteImage(string $url): ?string
    {
        $tempRaw = tempnam(sys_get_temp_dir(), 'vnvlogo');

        try {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => [
                    'ignore_errors' => true,
                    'timeout' => 10
                ]
            ]);

            $imageData = file_get_contents($url, false, $context);

            if ($imageData === false) {
                throw new \Exception("Failed to download image from URL");
            }

            file_put_contents($tempRaw, $imageData);

            $imageInfo = @getimagesize($tempRaw);
            if (!$imageInfo) {
                unlink($tempRaw);
                throw new \Exception("Downloaded file is not a valid image");
            }

            $ext = image_type_to_extension($imageInfo[2], false);
            $tempFile = $tempRaw . '.' . $ext;
            rename($tempRaw, $tempFile);

            return $tempFile;
        } catch (\Exception $e) {
            error_log('Error downloading remote image: ' . $e->getMessage());
            if (file_exists($tempRaw)) {
                unlink($tempRaw);
            }
            return null;
        }
    }

    protected function isValidImage(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = mime_content_type($path);

        return in_array($mime, $allowedTypes);
    }

    protected function getInstitutionField(string $field): string
    {
        return $this->institution[$field] ?? '';
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
    }
}
