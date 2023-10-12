// Define the GraphQL endpoint URL
export const graphqlUrl = "https://deprogramr.com";
export const graphqlEndpoint = "graphql";

export const generateGraphqlQuery = (first: number, categoryName: string) => `
{
  posts(first: ${first}, where: {categoryName: "${categoryName}", orderby: {field: MODIFIED, order: DESC}}) {

    edges {
      node {
        id
        title
        content
        categories {
          edges {
            node {
              id
              name
            }
          }
        }
        excerpt
        featuredImage {
          node {
            sourceUrl
            altText
          }
        }
        uri
      }
    }
  }
}
`;
