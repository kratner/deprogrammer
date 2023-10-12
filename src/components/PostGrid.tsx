import React from "react";
import { AgGridReact } from "ag-grid-react";
import "ag-grid-community/styles/ag-grid.css";
import "ag-grid-community/styles/ag-theme-alpine.css";
import {
  graphqlUrl,
  graphqlEndpoint,
  generateGraphqlQuery,
} from "../data/graphqlConfig";

interface ColumnDefinition {
  headerName: string;
  field: string;
  // Add other properties as needed
}

const PostGrid: React.FC = () => {
  const columnDefs: ColumnDefinition[] = [
    { headerName: "Title", field: "title" },
    { headerName: "Content", field: "content" },
    // Add other column definitions
  ];

  const defaultColDef = {
    // Define default column properties
  };

  const gridOptions = {
    // Set grid options
  };

  return (
    <div className="ag-theme-alpine" style={{ height: "500px", width: "100%" }}>
      <AgGridReact
        columnDefs={columnDefs}
        defaultColDef={defaultColDef}
        gridOptions={gridOptions}
        // Fetch data using your GraphQL query
        // rowData={/* Fetch data using your GraphQL query */}
      />
    </div>
  );
};

export default PostGrid;
