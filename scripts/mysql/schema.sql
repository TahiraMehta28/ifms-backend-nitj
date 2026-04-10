-- IFMS Database Schema for MySQL
-- Created for migration from MongoDB

CREATE DATABASE IF NOT EXISTS ifms_db;
USE ifms_db;

-- 1. Projects Table
CREATE TABLE IF NOT EXISTS projects (
    id VARCHAR(24) PRIMARY KEY, -- Storing MongoDB ObjectID as string
    gpNumber VARCHAR(50) UNIQUE,
    isOldProject TINYINT(1) DEFAULT 0,
    modeOfProject VARCHAR(100),
    projectName VARCHAR(255),
    projectAgencyName VARCHAR(255),
    sanctionOrderNo VARCHAR(255),
    nameOfScheme VARCHAR(255),
    piName VARCHAR(255),
    piEmail VARCHAR(255),
    department VARCHAR(255),
    projectStartDate DATETIME,
    projectEndDate DATETIME,
    originalEndDate DATETIME,
    hasExtension TINYINT(1) DEFAULT 0,
    totalYears FLOAT,
    totalSanctionedAmount DECIMAL(15, 2),
    totalAllocatedAmount DECIMAL(15, 2),
    totalReleasedAmount DECIMAL(15, 2),
    amountBookedByPI DECIMAL(15, 2) DEFAULT 0,
    actual_exp DECIMAL(15, 2) DEFAULT 0,
    bankDetails TEXT,
    sanctionedLetterFile LONGTEXT, -- To store base64 if needed, though file paths are better
    sanctionedLetterFileName VARCHAR(255),
    sanctionedLetterUploadedAt DATETIME,
    status VARCHAR(50) DEFAULT 'pending',
    createdAt DATETIME,
    updatedAt DATETIME
);

-- 2. Project Heads (Normalized from projects.heads)
CREATE TABLE IF NOT EXISTS project_heads_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projectId VARCHAR(24),
    headId VARCHAR(50),
    headName VARCHAR(255),
    headType VARCHAR(100),
    sanctionedAmount DECIMAL(15, 2),
    FOREIGN KEY (projectId) REFERENCES projects(id) ON DELETE CASCADE
);

-- 3. Budget Requests Table
CREATE TABLE IF NOT EXISTS budget_requests (
    id VARCHAR(24) PRIMARY KEY,
    requestNumber VARCHAR(50) UNIQUE,
    projectId VARCHAR(24),
    gpNumber VARCHAR(50),
    fileNumber VARCHAR(50),
    projectTitle VARCHAR(255),
    projectType VARCHAR(100),
    piName VARCHAR(255),
    piEmail VARCHAR(255),
    department VARCHAR(255),
    headId VARCHAR(50),
    headName VARCHAR(255),
    headType VARCHAR(100),
    requestedAmount DECIMAL(15, 2),
    actual_exp DECIMAL(15, 2) DEFAULT 0,
    snapshotProjectReleased DECIMAL(15, 2),
    snapshotProjectBooked DECIMAL(15, 2),
    snapshotProjectAvailable DECIMAL(15, 2),
    snapshotHeadReleased DECIMAL(15, 2),
    snapshotHeadBooked DECIMAL(15, 2),
    snapshotHeadAvailable DECIMAL(15, 2),
    purpose TEXT,
    description TEXT,
    material TEXT,
    mode TEXT,
    invoiceNumber VARCHAR(100),
    projectCompletionDate VARCHAR(100),
    quotation LONGTEXT, -- base64
    quotationFileName VARCHAR(255),
    status VARCHAR(50) DEFAULT 'pending',
    currentStage VARCHAR(50) DEFAULT 'da',
    previousStatus VARCHAR(50),
    hasOpenQuery TINYINT(1) DEFAULT 0,
    piResponse TEXT, -- Responses to latest queries
    daRemarks TEXT,
    arRemarks TEXT,
    drRemarks TEXT,
    drcOfficeRemarks TEXT,
    drcRcRemarks TEXT,
    drcRemarks TEXT,
    directorRemarks TEXT,
    rejectedBy VARCHAR(100),
    rejectedAtStage VARCHAR(50),
    rejectedAtStageLabel VARCHAR(100),
    rejectionRemarks TEXT,
    approvalType VARCHAR(100), -- Admin or Admin cum Financial
    expenditure TEXT, -- Point 7(b) - Editable by AR/DR
    specialApproval TINYINT(1) DEFAULT 0,
    approvedBy VARCHAR(100),
    approvedAt DATETIME,
    drcApprovedAt DATETIME,
    actual_expEnteredBy VARCHAR(100),
    actual_expEnteredAt DATETIME,
    createdAt DATETIME,
    updatedAt DATETIME,
    FOREIGN KEY (projectId) REFERENCES projects(id)
);

-- 4. Approval History (Normalized from budget_requests.approvalHistory)
CREATE TABLE IF NOT EXISTS approval_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requestId VARCHAR(24),
    stage VARCHAR(50),
    action VARCHAR(50),
    `by` VARCHAR(255),
    timestamp VARCHAR(100),
    remarks TEXT,
    approvalType VARCHAR(100),
    FOREIGN KEY (requestId) REFERENCES budget_requests(id) ON DELETE CASCADE
);

-- 5. Budget Request Queries (Normalized from budget_requests.queries and latestQuery)
CREATE TABLE IF NOT EXISTS budget_request_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requestId VARCHAR(24),
    `by` VARCHAR(255),
    byLabel VARCHAR(100),
    `to` VARCHAR(100),
    query TEXT,
    stage VARCHAR(50),
    timestamp VARCHAR(100),
    resolved TINYINT(1) DEFAULT 0,
    piResponse TEXT,
    resolvedAt DATETIME,
    FOREIGN KEY (requestId) REFERENCES budget_requests(id) ON DELETE CASCADE
);

-- 6. Head Allocations Table
CREATE TABLE IF NOT EXISTS head_allocations (
    id VARCHAR(24) PRIMARY KEY,
    projectId VARCHAR(24),
    gpNumber VARCHAR(50),
    headId VARCHAR(50),
    headName VARCHAR(255),
    headType VARCHAR(100),
    sanctionedAmount DECIMAL(15, 2),
    releasedAmount DECIMAL(15, 2) DEFAULT 0,
    bookedAmount DECIMAL(15, 2) DEFAULT 0,
    actual_exp DECIMAL(15, 2) DEFAULT 0,
    remainingAmount DECIMAL(15, 2),
    timePeriod VARCHAR(100),
    bankDetails TEXT,
    status VARCHAR(50),
    createdAt DATETIME,
    updatedAt DATETIME,
    FOREIGN KEY (projectId) REFERENCES projects(id) ON DELETE CASCADE
);

-- 7. Fund Allocations Table
CREATE TABLE IF NOT EXISTS fund_allocations (
    id VARCHAR(24) PRIMARY KEY,
    projectId VARCHAR(24),
    gpNumber VARCHAR(50),
    totalAllocated DECIMAL(15, 2),
    totalReleased DECIMAL(15, 2),
    createdAt DATETIME,
    updatedAt DATETIME,
    FOREIGN KEY (projectId) REFERENCES projects(id) ON DELETE CASCADE
);

-- 8. Fund Allocation Items (Normalized from fund_allocations.allocations)
CREATE TABLE IF NOT EXISTS fund_allocation_items (
    id VARCHAR(24) PRIMARY KEY, -- This uses the item ID from MongoDB
    fundAllocationId VARCHAR(24),
    headId VARCHAR(50),
    headName VARCHAR(255),
    headType VARCHAR(100),
    sanctionedAmount DECIMAL(15, 2),
    releasedAmount DECIMAL(15, 2),
    remainingAmount DECIMAL(15, 2),
    timePeriod VARCHAR(100),
    bankDetails TEXT,
    status VARCHAR(50),
    FOREIGN KEY (fundAllocationId) REFERENCES fund_allocations(id) ON DELETE CASCADE
);

-- 9. Fund Releases Table
CREATE TABLE IF NOT EXISTS fund_releases (
    id VARCHAR(24) PRIMARY KEY,
    projectId VARCHAR(24),
    gpNumber VARCHAR(50),
    piName VARCHAR(255),
    piEmail VARCHAR(255),
    releaseNumber VARCHAR(50),
    sanctionOrderNo VARCHAR(100),
    letterNumber VARCHAR(100),
    letterDate VARCHAR(100),
    totalReleasedAmount DECIMAL(15, 2),
    createdAt DATETIME,
    updatedAt DATETIME,
    FOREIGN KEY (projectId) REFERENCES projects(id) ON DELETE CASCADE
);

-- 10. Headwise Releases (Normalized from fund_releases.headwiseReleases)
CREATE TABLE IF NOT EXISTS headwise_releases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fundReleaseId VARCHAR(24),
    headId VARCHAR(50),
    headName VARCHAR(255),
    headType VARCHAR(100),
    sanctionedAmount DECIMAL(15, 2),
    releaseAmount DECIMAL(15, 2),
    FOREIGN KEY (fundReleaseId) REFERENCES fund_releases(id) ON DELETE CASCADE
);

-- 11. Project Files Table
CREATE TABLE IF NOT EXISTS project_files (
    id VARCHAR(24) PRIMARY KEY,
    projectId VARCHAR(24),
    gpNumber VARCHAR(50),
    fileName VARCHAR(255),
    storedFileName VARCHAR(255),
    fileType VARCHAR(100),
    filePath TEXT,
    fileSize INT,
    mimeType VARCHAR(100),
    uploadedBy VARCHAR(255),
    uploadedAt DATETIME,
    FOREIGN KEY (projectId) REFERENCES projects(id) ON DELETE CASCADE
);

-- 12. Release Audit Log (Immutable ledger)
CREATE TABLE IF NOT EXISTS release_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projectId VARCHAR(24),
    gpNumber VARCHAR(50),
    projectName VARCHAR(255),
    auditKey VARCHAR(255),
    releaseNumber VARCHAR(50),
    letterNumber VARCHAR(100),
    letterDate VARCHAR(100),
    headId VARCHAR(50),
    headName VARCHAR(255),
    headType VARCHAR(100),
    amountReleased DECIMAL(15, 2),
    releasedBy VARCHAR(255),
    remarks TEXT,
    releaseDate DATETIME,
    loggedAt DATETIME,
    source VARCHAR(100) DEFAULT 'release-funds-headwise',
    FOREIGN KEY (projectId) REFERENCES projects(id) ON DELETE CASCADE
);

-- 13. Master Project Heads (Global directory)
CREATE TABLE IF NOT EXISTS master_project_heads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    type VARCHAR(100) NOT NULL,
    description TEXT,
    isActive TINYINT(1) DEFAULT 1,
    createdAt DATETIME,
    updatedAt DATETIME
);

-- 14. Project Extensions Table
CREATE TABLE IF NOT EXISTS project_extensions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projectId VARCHAR(24),
    originalEndDate DATETIME,
    extendedEndDate DATETIME,
    additionalYears FLOAT,
    remarks TEXT,
    extendedBy VARCHAR(255),
    extensionPdfPath TEXT,
    extensionPdfOriginalName VARCHAR(255),
    createdAt DATETIME,
    updatedAt DATETIME,
    FOREIGN KEY (projectId) REFERENCES projects(id) ON DELETE CASCADE
);
